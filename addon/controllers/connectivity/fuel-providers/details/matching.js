import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class ConnectivityFuelProvidersDetailsMatchingController extends Controller {
    @service hostRouter;

    @action reviewUnmatchedTransactions() {
        return this.hostRouter.transitionTo('console.fleet-ops.management.fuel-transactions.index', {
            queryParams: { sync_status: 'unmatched', connection: this.model.id, page: 1 },
        });
    }
}
