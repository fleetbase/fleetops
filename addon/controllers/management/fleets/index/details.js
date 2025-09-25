import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';

export default class ManagementFleetsIndexDetailsController extends Controller {
    @service hostRouter;
    @tracked tabs = [
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
    @tracked actionButtons = [
        {
            icon: 'pencil',
            fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.fleets.index.edit', this.model),
        },
    ];
}
