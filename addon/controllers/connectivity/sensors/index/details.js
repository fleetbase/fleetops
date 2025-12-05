import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

export default class ConnectivitySensorsIndexDetailsController extends Controller {
    @service('universe/menu-service') menuService;
    @service hostRouter;

    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:place:details');
        return [
            {
                route: 'connectivity.sensors.index.details.index',
                label: 'Overview',
            },
            ...(isArray(registeredTabs) ? registeredTabs : []),
        ];
    }

    get actionButtons() {
        return [
            {
                icon: 'pencil',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.connectivity.sensors.index.edit', this.model),
            },
        ];
    }
}
