import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { debug } from '@ember/debug';
import { next } from '@ember/runloop';
import { task } from 'ember-concurrency';

/**
 * The Overview tab of an inspection record.
 *
 * The answers are read from the submission's own payload — the resource
 * projects them with each field's identity and every file reference resolved —
 * and laid out against the form's groups, so the record reads the way the form
 * was built. Nothing is written here.
 */
export default class InspectionSubmissionDetailsComponent extends Component {
    @service inspectionFormActions;
    @service inspectionSubmissionActions;

    @tracked groups = [];
    @tracked values = {};

    constructor() {
        super(...arguments);
        next(() => this.load.perform());
    }

    get hasAnswers() {
        return this.groups.some((group) => (group.fields ?? []).length > 0);
    }

    @task *load() {
        const submission = this.args.resource;
        if (!submission?.id) {
            return;
        }

        try {
            this.values = yield this.inspectionSubmissionActions.loadAnswers(submission);

            const form = submission.form;
            if (form?.id) {
                this.groups = yield this.inspectionFormActions.loadStructure(form);
            }
        } catch (error) {
            debug('Unable to load inspection answers: ' + error.message);
        }
    }
}
