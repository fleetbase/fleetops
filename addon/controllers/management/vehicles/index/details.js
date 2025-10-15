import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';

export default class ManagementVehiclesIndexDetailsController extends Controller {
    @service hostRouter;
    @tracked tabs = [
        {
            route: 'management.vehicles.index.details.index',
            label: 'Overview',
        },
    ];
    @tracked actionButtons = [
        {
            icon: 'pencil',
            fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.vehicles.index.edit', this.model),
        },
    ];
}
