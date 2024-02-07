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

    @service fetch;

    @service notifications;

    @tracked uploadQueue = [];

    @tracked selectedCategory = null;

    @tracked filteredFiles = [];

    constructor() {
        super(...arguments);
        this.avatar = { files: [] };
    }

    @action selectCategory(category) {
        this.selectedCategory = category;
        this.loadFilesByType.perform();
    }

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

    @action removeFile(file) {
        return file.destroyRecord();
    }

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
