import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

export default class ManagementFleetsIndexDetailsController extends Controller {
    @service('universe/menu-service') menuService;
    @service fleetActions;
    @service hostRouter;

    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:place:details');
        return [
            {
                route: 'management.fleets.index.details.index',
                label: 'Overview',
            },
            {
                route: 'management.fleets.index.details.vehicles',
                label: 'Vehicles',
            },
            {
                route: 'management.fleets.index.details.drivers',
                label: 'Drivers',
            },
            ...(isArray(registeredTabs) ? registeredTabs : []),
        ];
    }

    get actionButtons() {
        return [
            {
                icon: 'pencil',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.fleets.index.edit', this.model),
            },
            {
                icon: 'user-plus',
                fn: () => this.fleetActions.assignDriver(this.model),
                helpText: 'Assign driver',
            },
            {
                icon: 'car',
                fn: () => this.fleetActions.assignVehicle(this.model),
                helpText: 'Assign vehicle',
            },
        ];
    }
}
