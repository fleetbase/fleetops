import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

export default class ConnectivityDevicesIndexDetailsController extends Controller {
    @service('universe/menu-service') menuService;
    @service deviceActions;
    @service hostRouter;

    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:device:details');
        return [
            {
                route: 'connectivity.devices.index.details.index',
                label: 'Overview',
            },
            ...(isArray(registeredTabs) ? registeredTabs : []),
        ];
    }

    get actionButtons() {
        return [
            {
                icon: 'pencil',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.connectivity.devices.index.edit', this.model),
            },
            {
                icon: 'link',
                fn: () => this.deviceActions.attachToVehicle(this.model),
                helpText: 'Attach to vehicle',
            },
            {
                icon: 'unlink',
                fn: () => this.deviceActions.detachFromVehicle(this.model),
                helpText: 'Detach from vehicle',
            },
        ];
    }
}
