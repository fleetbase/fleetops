import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

export default class ManagementContactsCustomersDetailsController extends Controller {
    @service('universe/menu-service') menuService;
    @service hostRouter;
    @service intl;

    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:customer:details');
        return [
            {
                route: 'management.contacts.index.details.index',
                label: 'Overview',
            },
            ...(isArray(registeredTabs) ? registeredTabs : []),
        ];
    }

    get actionButtons() {
        return [
            {
                icon: 'pencil',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.contacts.customers.edit', this.model),
            },
        ];
    }
}
