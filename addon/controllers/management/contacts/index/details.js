import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class ManagementContactsIndexDetailsController extends Controller {
    @service contactActions;
    @service hostRouter;

    get tabs() {
        return [
            {
                route: 'management.contacts.index.details.index',
                label: 'Overview',
            },
        ];
    }

    get actionButtons() {
        const buttons = [
            {
                icon: 'pencil',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.contacts.index.edit', this.model),
            },
        ];

        const accountActionButton = this.contactActions.accountActionButton(this.model);
        if (accountActionButton) {
            buttons.push(accountActionButton);
        }

        return buttons;
    }
}
