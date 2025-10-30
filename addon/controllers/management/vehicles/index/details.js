import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class ManagementVehiclesIndexDetailsController extends Controller {
    @service hostRouter;

    get tabs() {
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
