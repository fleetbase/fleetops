import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class InspectionSubmissionActionsService extends ResourceActionService {
    @service fetch;
    @service notifications;

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

    @action async createIssue(submission) {
        return this.postAction(submission, 'create-issue', 'Issue created from failed inspection items.');
    }

    @action async createWorkOrder(submission) {
        return this.postAction(submission, 'create-work-order', 'Work order created from failed inspection items.');
    }

    @action async resolve(submission) {
        return this.postAction(submission, 'resolve', 'Inspection resolved.');
    }

    async postAction(submission, actionName, message) {
        try {
            await this.fetch.post(`inspection-submissions/${submission.id}/${actionName}`);
            this.notifications.success(message);
            await this.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
