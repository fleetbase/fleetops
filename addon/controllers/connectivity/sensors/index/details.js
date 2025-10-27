import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class ConnectivitySensorsIndexDetailsController extends Controller {
    @service hostRouter;

    get tabs() {
        return [
            {
                route: 'connectivity.sensors.index.details.index',
                label: 'Overview',
            },
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
