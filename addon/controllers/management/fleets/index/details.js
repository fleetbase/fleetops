import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class ManagementFleetsIndexDetailsController extends Controller {
    @service hostRouter;

    get tabs() {
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
        ];
    }

    get actionButtons() {
        return [
            {
                icon: 'pencil',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.fleets.index.edit', this.model),
            },
        ];
    }
}
