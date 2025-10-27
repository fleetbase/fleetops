import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class ConnectivityTelematicsIndexDetailsController extends Controller {
    @service hostRouter;

    get tabs() {
        return [
            {
                route: 'connectivity.telematics.index.details.index',
                label: 'Overview',
            },
        ];
    }

    get actionButtons() {
        return [
            {
                icon: 'pencil',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.connectivity.telematics.index.edit', this.model),
            },
        ];
    }
}
