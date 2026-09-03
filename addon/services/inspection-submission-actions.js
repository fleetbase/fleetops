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
