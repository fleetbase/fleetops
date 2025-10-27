import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class ConnectivityDevicesIndexDetailsController extends Controller {
    @service hostRouter;

    get tabs() {
        return [
            {
                route: 'connectivity.devices.index.details.index',
                label: 'Overview',
            },
        ];
    }

    get actionButtons() {
        return [
            {
                icon: 'pencil',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.connectivity.devices.index.edit', this.model),
            },
        ];
    }
}
