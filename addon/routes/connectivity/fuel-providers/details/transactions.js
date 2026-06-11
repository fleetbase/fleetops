import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectivityFuelProvidersIndexDetailsTransactionsRoute extends Route {
    @service store;

    model() {
        const connection = this.modelFor('connectivity.fuel-providers.details');
        return this.store.query('fuel-provider-transaction', {
            connection: connection.uuid,
            sort: '-transaction_at',
        });
    }
}
