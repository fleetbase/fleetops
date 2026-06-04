import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class ManagementContactsCustomersDetailsController extends Controller {
    @service customerActions;
    @service hostRouter;

    get tabs() {
        return [
            {
                route: 'management.contacts.customers.details.index',
                label: 'Overview',
            },
        ];
    }

    get actionButtons() {
        const buttons = [
            {
                icon: 'pencil',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.contacts.customers.edit', this.model),
            },
        ];

        const accountActionButton = this.customerActions.accountActionButton(this.model);
        if (accountActionButton) {
            buttons.push(accountActionButton);
        }

        return buttons;
    }
}
