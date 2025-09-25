import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';

export default class ManagementContactsCustomersDetailsController extends Controller {
    @service hostRouter;
    @tracked tabs = [
        {
            route: 'management.contacts.customers.details.index',
            label: 'Overview',
        },
    ];
    @tracked actionButtons = [
        {
            icon: 'pencil',
            fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.contacts.customers.edit', this.model),
        },
    ];
}
