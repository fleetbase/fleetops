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
    @service intl;

    @tracked groups = [];
    @tracked values = {};

    constructor() {
        super(...arguments);
        next(() => {
            if (this.isDestroying || this.isDestroyed) {
                return;
            }

            this.load.perform();
        });
    }

    /**
     * Who filed it: the account it is credited to — whoever signed in to the
     * console, or whoever a public link was for — or else the name typed on
     * the link, when the link was for nobody in particular.
     */
    get submitter() {
        const submission = this.args.resource;
        return submission?.submitted_by?.name ?? submission?.meta?.completed_by_name ?? null;
    }

    /**
     * How that was established, for a submission that came through a public
     * link: whether a PIN stood in the way, and what name was typed when it
     * differs from the account or there is no account to check it against.
     */
    get submitterNote() {
        const submission = this.args.resource;
        if (submission?.source !== 'public_link') {
            return null;
        }

        const typed = (submission.meta?.completed_by_name ?? '').trim();
        const account = (submission.submitted_by?.name ?? '').trim();
        const notes = [this.intl.t(submission.meta?.pin_verified ? 'inspection.record.via-link-pin' : 'inspection.record.via-link')];

        if (typed && account && typed.toLowerCase() !== account.toLowerCase()) {
            notes.push(this.intl.t('inspection.record.signed-as', { name: typed }));
        } else if (typed && !account) {
            notes.push(this.intl.t('inspection.record.name-unverified'));
        }

        return notes.join(' · ');
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
