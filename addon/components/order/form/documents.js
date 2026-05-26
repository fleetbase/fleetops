import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class OrderFormDocumentsComponent extends Component {
    @service fetch;
    @service notifications;
    @tracked uploadQueue = [];
    @tracked acceptedFileTypes = [
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/msword',
        'application/pdf',
        'application/x-pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'video/mp4',
        'video/quicktime',
        'video/x-msvideo',
        'video/x-flv',
        'video/x-ms-wmv',
        'audio/mpeg',
        'video/x-msvideo',
        'application/zip',
        'application/x-tar',
    ];

    @task *queueFile(file) {
        if (['queued', 'failed', 'timed_out', 'aborted'].indexOf(file.state) === -1) return;

        try {
            this.uploadQueue.pushObject(file);
            const uploadOptions = {
                path: 'uploads/fleet-ops/order-files',
                type: 'order_file',
            };

            if (!this.args.resource.isNew) {
                uploadOptions.subject_uuid = this.args.resource.id;
                uploadOptions.subject_type = 'fleet-ops:order';
            }

            yield this.fetch.uploadFile.perform(
                file,
                uploadOptions,
                (uploadedFile) => {
                    this.args.resource.files.pushObject(uploadedFile);
                    this.uploadQueue.removeObject(file);
                },
                () => {
                    this.uploadQueue.removeObject(file);
                    if (file.queue && typeof file.queue.remove === 'function') {
                        file.queue.remove(file);
                    }
                }
            );
        } catch (err) {
            debug('Order document upload failed: ' + err.message);
            this.notifications.serverError(err);
        }
    }

    @task *removeFile(file) {
        yield file.destroyRecord();
    }
}
