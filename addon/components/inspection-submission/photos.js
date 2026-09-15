import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debug } from '@ember/debug';
import { next } from '@ember/runloop';
import { task } from 'ember-concurrency';

const PHOTO_TYPE = 'inspection_photo';

/**
 * The Photos tab of an inspection record.
 *
 * Every photo filed against the submission — the ones the driver sent with a
 * failed pass-fail answer, stored by `InspectionFileStore`, and the ones added
 * here — is a platform file whose subject is the submission, so one query
 * finds them all and `ModelMultiFileUpload` adds to the same pile.
 */
export default class InspectionSubmissionPhotosComponent extends Component {
    @service store;

    @tracked files = [];

    photoType = PHOTO_TYPE;

    constructor() {
        super(...arguments);
        next(() => {
            if (this.isDestroying || this.isDestroyed) {
                return;
            }

            this.load.perform();
        });
    }

    @task *load() {
        const submission = this.args.resource;
        // Files are filed against the submission's uuid, which is what the
        // internal resource answers beside the id the console addresses it by.
        const subjectUuid = submission?.uuid ?? submission?.id;
        if (!subjectUuid) {
            return;
        }

        try {
            const files = yield this.store.query('file', { subject_uuid: subjectUuid, type: PHOTO_TYPE, limit: -1 });
            this.files = files.toArray();
        } catch (error) {
            debug('Unable to load inspection photos: ' + error.message);
        }
    }

    @action addFile(file) {
        this.files = [...this.files, file];
    }
}
