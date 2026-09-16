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

    get actionButtons() {
        return [
            {
                icon: 'pencil',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.issues.index.edit', this.model),
            },
            {
                icon: 'ellipsis',
                type: 'default',
                items: this.issueActions.workflowItems(this.model, () => this.refreshIssue()),
            },
        ];
    }

    refreshIssue() {
        return this.model?.reload?.();
    }
}
