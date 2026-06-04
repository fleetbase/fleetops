import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';

export default class ManagementIssuesIndexDetailsController extends Controller {
    @service hostRouter;
    @service issueActions;

    @tracked tabs = [
        {
            route: 'management.issues.index.details.index',
            label: 'Overview',
        },
    ];

    get isClosed() {
        return ['closed', 'resolved', 'completed'].includes(this.model?.status);
    }

    get actionButtons() {
        const workflowItems = [
            {
                label: 'Change Status',
                icon: 'arrows-rotate',
                fn: () => this.issueActions.openStatusModal(this.model, { onSaved: () => this.refreshIssue() }),
            },
            {
                label: 'Assign Issue',
                icon: 'user-check',
                fn: () => this.issueActions.openAssignModal(this.model, { onSaved: () => this.refreshIssue() }),
            },
        ];

        if (this.isClosed) {
            workflowItems.push({
                label: 'Re-open Issue',
                icon: 'rotate-left',
                fn: () => this.issueActions.confirmReopenIssue(this.model, { onSaved: () => this.refreshIssue() }),
            });
        } else {
            workflowItems.push({
                label: 'Close Issue',
                icon: 'circle-check',
                class: 'text-green-600 dark:text-green-400',
                fn: () => this.issueActions.openCloseIssueModal(this.model, { onSaved: () => this.refreshIssue() }),
            });
        }

        return [
            {
                icon: 'pencil',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.issues.index.edit', this.model),
            },
            {
                icon: 'ellipsis',
                type: 'default',
                items: workflowItems,
            },
        ];
    }

    refreshIssue() {
        return this.model?.reload?.();
    }
}
