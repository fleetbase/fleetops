import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class IssueDetailsDocumentsComponent extends Component {
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
        'application/zip',
        'application/x-tar',
    ];

    get files() {
        return this.args.resource?.files ?? [];
    }

    @task *queueFile(file) {
        if (['queued', 'failed', 'timed_out', 'aborted'].indexOf(file.state) === -1) return;

        try {
            this.uploadQueue.pushObject(file);
            yield this.fetch.uploadFile.perform(
                file,
                {
                    path: 'uploads/fleet-ops/issue-files',
                    subject_uuid: this.args.resource.id,
                    subject_type: 'fleet-ops:issue',
                    type: 'issue_file',
                },
                (uploadedFile) => {
                    if (this.args.resource.files?.pushObject) {
                        this.args.resource.files.pushObject(uploadedFile);
                    }
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
            debug('Issue document upload failed: ' + err.message);
            this.notifications.serverError(err);
        }
    }

    @task *removeFile(file) {
        yield file.destroyRecord();
        if (this.args.resource.files?.removeObject) {
            this.args.resource.files.removeObject(file);
        }
    }

    @action downloadFile(file) {
        if (typeof file.download === 'function') {
            return file.download();
        }

        if (file.url) {
            window.open(file.url, '_blank');
        }
    }
}
