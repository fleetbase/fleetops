import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';

export default class ManagementPlacesIndexDetailsController extends Controller {
    @service hostRouter;
    @tracked tabs = [
        {
            route: 'management.places.index.details.index',
            label: 'Overview',
        },
    ];
    @tracked actionButtons = [
        {
            icon: 'pencil',
            fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.places.index.edit', this.model),
        },
    ];
}
