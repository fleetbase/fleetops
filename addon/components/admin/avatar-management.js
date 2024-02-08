import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';
export default class AdminAvatarManagementComponent extends Component {
    /**
     * Inject the `fileQueue` service
     *
     * @var {Service}
     */
    @service fileQueue;

    /**
     * Inject the `fetch` service for making network requests.
     *
     * @var {Service}
     */
    @service fetch;

    /**
     * Inject the `notifications` service for handling notifications.
     *
     * @var {Service}
     */
    @service notifications;

    /**
     * Tracks the files in the upload queue.
     *
     * @var {Array}
     */
    @tracked uploadQueue = [];

    /**
     * Tracks the selected category for avatar management.
     *
     * @var {string|null}
     */
    @tracked selectedCategory = null;

    /**
     * Tracks the filtered files based on the selected category.
     *
     * @var {Array}
     */
    @tracked filteredFiles = [];

    /**
     * Initializes the component with an empty avatar object.
     *
     * @constructor
     */
    constructor() {
        super(...arguments);
        this.avatar = { files: [] };
    }

    /**
     * Action triggered when a category is selected.
     *
     * @param {string} category - The selected category.
     */
    @action selectCategory(category) {
        this.selectedCategory = category;
        this.loadFilesByType.perform();
    }

    /**
     * Action triggered when a file is queued for upload.
     *
     * @param {File} file - The file to be queued.
     */
    @action queueFile(file) {
        if (['queued', 'failed', 'timed_out', 'aborted'].indexOf(file.state) === -1) {
            return;
        }

        let uploadPath;
        let uploadType;
        switch (this.selectedCategory) {
            case 'vehicles':
                uploadPath = '/custom-avatars/vehicles';
                uploadType = 'vehicle_avatar';
                break;
            case 'places':
                uploadPath = '/custom-avatars/places';
                uploadType = 'place_avatar';
                break;
            case 'drivers':
                uploadPath = '/custom-avatars/drivers';
                uploadType = 'driver_avatar';
                break;
            default:
                throw new Error('Invalid category selected');
        }

        this.uploadQueue.pushObject(file);
        this.fetch.uploadFile.perform(
            file,
            {
                path: uploadPath,
                type: uploadType,
            },
            (uploadedFile) => {
                this.avatar.files.pushObject(uploadedFile);
                this.uploadQueue.removeObject(file);
            },
            () => {
                this.uploadQueue.removeObject(file);

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
        return file.destroyRecord();
    }

    /**
     * Task that loads files based on the selected category.
     *
     * @task
     * @generator
     * @yields {Array} - The filtered files based on the selected category.
     */
    @task *loadFilesByType() {
        let type = {
            vehicles: 'vehicle_avatar',
            places: 'place_avatar',
            drivers: 'driver_avatar',
        }[this.selectedCategory];
        const filteredFiles = yield this.fetch.get('files', { type: type }).catch((error) => {
            this.notifications.serverError(error);
        });
        if (filteredFiles) {
            this.filteredFiles = filteredFiles;
        }

        return filteredFiles;
    }
}
