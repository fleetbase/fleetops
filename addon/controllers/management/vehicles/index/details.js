import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

export default class ManagementVehiclesIndexDetailsController extends Controller {
    @service('universe/menu-service') menuService;
    @service hostRouter;

    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:vehicle:details');
        return [
            {
                route: 'management.vehicles.index.details.index',
                label: 'Overview',
            },
            {
                route: 'management.vehicles.index.details.positions',
                label: 'Positions',
            },
            {
                route: 'management.vehicles.index.details.devices',
                label: 'Devices',
            },
            ...(isArray(registeredTabs) ? registeredTabs : []),
        ];
    }

    get actionButtons() {
        return [
            {
                icon: 'pencil',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.vehicles.index.edit', this.model),
            },
        ];
    }
}
