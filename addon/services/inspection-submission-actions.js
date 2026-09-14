import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class InspectionSubmissionActionsService extends ResourceActionService {
    @service fetch;
    @service notifications;
    @service modalsManager;
    @service intl;

    constructor() {
        super(...arguments);
        this.initialize('inspection-submission', {
            defaultAttributes: {
                type: 'dvir',
                status: 'draft',
                source: 'console',
                item_results: [],
            },
        });
    }

    transition = {
        view: (submission) => this.transitionTo('maintenance.inspection-submissions.index.details', submission),
        edit: (submission) => this.transitionTo('maintenance.inspection-submissions.index.edit', submission),
        create: () => this.transitionTo('maintenance.inspection-submissions.index.new'),
    };

    /**
     * The answers filed against a submission, as the resource projects them:
     * every value beside the field it answers, with `file:<uuid>` references
     * resolved to something fetchable.
     *
     * The `inspection-submission` model belongs to `@fleetbase/fleetops-data`
     * and declares no `custom_field_values` relationship, so Ember Data drops
     * the projection; this reads it from the internal payload directly.
     *
     * @return {Object} the answers keyed by the field uuid they answer
     */
    async loadAnswers(submission) {
        if (!submission?.id) {
            return {};
        }

        const response = await this.fetch.get(`inspection-submissions/${submission.id}`);
        const record = response?.inspection_submission ?? response?.inspectionSubmission ?? response;
        const values = Array.isArray(record?.custom_field_values) ? record.custom_field_values : [];

        return values.reduce((carry, value) => {
            const key = value?.custom_field;
            if (key) {
                carry[key] = value.value;
            }

            return carry;
        }, {});
    }

    /**
     * Writes the answers. `inspection_submission.custom_field_values` is what
     * `InspectionSubmissionController::syncAnswersFromRequest()` reads, and it
     * is the same body the driver API accepts — the console and the app write
     * the same rows, and the server derives the item results from the
     * pass-fail answers among them.
     *
     * @param {Array} rows [{ custom_field, value, value_type }]
     */
    async saveAnswers(submission, rows) {
        if (!submission?.id || !Array.isArray(rows) || rows.length === 0) {
            return null;
        }

        return this.fetch.put(`inspection-submissions/${submission.id}`, {
            inspection_submission: { custom_field_values: rows },
        });
    }

    @action async submit(submission) {
        return this.postAction(submission, 'submit', 'Inspection submitted.');
    }

    /**
     * Each of these used to fire on click: the first anyone knew of a new
     * issue was a toast and an id. They ask first, and say what they are about
     * to make and from which failed checks.
     */
    @action createIssue(submission) {
        return this.confirmFollowUp(submission, {
            kind: 'issue',
            title: this.intl.t('inspection.follow-up.create-issue-title'),
            summary: this.intl.t('inspection.follow-up.create-issue-summary'),
            acceptButtonText: this.intl.t('inspection.follow-up.create-issue-accept'),
            acceptButtonIcon: 'triangle-exclamation',
            endpoint: 'create-issue',
            message: 'Issue created from failed inspection items.',
        });
    }

    @action createWorkOrder(submission) {
        return this.confirmFollowUp(submission, {
            kind: 'work-order',
            title: this.intl.t('inspection.follow-up.create-work-order-title'),
            summary: this.intl.t('inspection.follow-up.create-work-order-summary'),
            acceptButtonText: this.intl.t('inspection.follow-up.create-work-order-accept'),
            acceptButtonIcon: 'clipboard-list',
            dueNote: this.dueNoteFor(submission),
            endpoint: 'create-work-order',
            message: 'Work order created from failed inspection items.',
        });
    }

    @action resolve(submission) {
        return this.confirmFollowUp(submission, {
            kind: 'resolve',
            title: this.intl.t('inspection.follow-up.resolve-title'),
            summary: this.intl.t('inspection.follow-up.resolve-summary'),
            acceptButtonText: this.intl.t('inspection.follow-up.resolve-accept'),
            acceptButtonIcon: 'check',
            endpoint: 'resolve',
            message: 'Inspection resolved.',
        });
    }

    /** When the work order falls due, which the server takes from the worst failure. */
    dueNoteFor(submission) {
        const failures = (submission?.item_results ?? []).filter((item) => item.passed === false);
        const critical = failures.some((item) => item.severity === 'critical');
        const due = new Date();
        due.setDate(due.getDate() + (critical ? 1 : 7));

        return this.intl.t('inspection.follow-up.due-note', { due: due.toLocaleDateString() });
    }

    confirmFollowUp(submission, { kind, title, summary, acceptButtonText, acceptButtonIcon, dueNote, endpoint, message }) {
        const failures = (submission?.item_results ?? []).filter((item) => item.passed === false);
        const nothingToDo = kind !== 'resolve' && failures.length === 0;

        return this.modalsManager.show('modals/inspection-follow-up', {
            title,
            summary,
            submission,
            kind,
            dueNote,
            acceptButtonText,
            acceptButtonIcon,
            // Nothing failed: the server would only say so once the request was
            // already made, which is a strange moment to find out.
            acceptButtonDisabled: nothingToDo,
            declineButtonText: this.intl.t('inspection.follow-up.cancel'),
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.postAction(submission, endpoint, message);
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    async postAction(submission, actionName, message) {
        const response = await this.fetch.post(`inspection-submissions/${submission.id}/${actionName}`);

        // The endpoints answer with what they did, and say so when a submission
        // had nothing to raise.
        this.notifications.success(response?.message ?? message);
        await this.refresh();

        return response;
    }
}
