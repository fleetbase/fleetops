import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';

export default class OperationsServiceRatesIndexDetailsController extends Controller {
    @service hostRouter;
    @tracked tabs = [
        {
            route: 'operations.service-rates.index.details.index',
            label: 'Overview',
        },
    ];
    @tracked actionButtons = [
        {
            icon: 'pencil',
            fn: () => this.hostRouter.transitionTo('console.fleet-ops.operations.service-rates.index.edit', this.model),
        },
    ];
}
