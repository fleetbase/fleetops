import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';
import { pluralize } from 'ember-inflector';

export default class AdminAvatarManagementComponent extends Component {
    @service fileQueue;
    @service store;
    @service fetch;
    @service notifications;
    @service currentUser;
    @service intl;

    /**
     * Tracks the files in the upload queue.
     *
     * @var {Array}
     */
    @tracked uploadQueue = [];

    /**
     * The current loaded avatars.
     *
     * @var {Array}
     */
    @tracked avatars = [];

    /**
     * Tracks the selected category for avatar management.
     *
     * @var {string|null}
     */
    @tracked currentCategory;

    /**
     * The only acceptable file types for avatars, png or svg.
     *
     * @memberof AdminAvatarManagementComponent
     */
    get acceptedFileTypes() {
        return ['image/svg+xml', 'image/png'];
    }

    /**
     * Selectable categories for avatar management.
     *
     * @memberof AdminAvatarManagementComponent
     */
    get categories() {
        return [
            {
                name: this.intl.t('resource.vehicles'),
                icon: 'car',
                type: 'vehicle',
                avatars: [],
            },
            {
                name: this.intl.t('resource.places'),
                icon: 'building',
                type: 'place',
                avatars: [],
            },
            {
                name: this.intl.t('resource.drivers'),
                icon: 'id-card',
                type: 'driver',
                avatars: [],
            },
        ];
    }

    /**
     * Creates an instance of AdminAvatarManagementComponent.
     * @memberof AdminAvatarManagementComponent
     */
    constructor() {
        super(...arguments);
        this.loadAvatars.perform();
    }

    /**
     * Action triggered when a category is selected.
     *
     * @param {string} category - The selected category.
     */
    @action switchCategory(category) {
        this.currentCategory = category;
    }

    /**
     * Action triggered when a file is queued for upload.
     *
     * @param {File} file - The file to be queued.
     */
    @action queueFile(file) {
        // since we have dropzone and upload button within dropzone validate the file state first
        // as this method can be called twice from both functions
        if (['queued', 'failed', 'timed_out', 'aborted'].indexOf(file.state) === -1) {
            return;
        }

        // Get the current category
        const category = this.currentCategory;

        // Queue and upload immediatley
        this.uploadQueue.pushObject(file);
        this.fetch.uploadFile.perform(
            file,
            {
                path: `custom-avatars/${pluralize(category.type)}/${this.currentUser.companyId}`,
                type: `${category.type}-avatar`,
            },
            (uploadedFile) => {
                this.currentCategory.avatars.pushObject(uploadedFile);
                this.uploadQueue.removeObject(file);
            },
            () => {
                this.uploadQueue.removeObject(file);
                // remove file from queue
                if (file.queue && typeof file.queue.remove === 'function') {
                    file.queue.remove(file);
                }
            }
        );
    }

    /**
     * Action triggered when a file is removed.
     *
     * @param {File} file - The file to be removed.
     * @returns {Promise} - A promise representing the file destruction operation.
     */
    @action removeFile(file) {
        this.currentCategory.avatars.removeObject(file);
        return file.destroyRecord();
    }

    /**
     * Task that loads files based on the selected category.
     *
     * @task
     * @generator
     * @yields {Array} - The filtered files based on the selected category.
     */
    @task *loadAvatars() {
        this.avatars = yield this.store.query('file', { type_ends_with: 'avatar' });

        // Assign avatars to their categories
        if (isArray(this.avatars)) {
            const categoriesWithAvatars = [];

            for (let i = 0; i < this.categories.length; i++) {
                const category = this.categories[i];
                categoriesWithAvatars.pushObject({
                    ...category,
                    avatars: this.avatars.filter((file) => file.type === `${category.type}-avatar`),
                });
            }

            this.categories = categoriesWithAvatars;
            this.currentCategory = this.currentCategory ? this.categories.find((category) => category.type === this.currentCategory.type) : this.categories[0];
        }
    }
}
