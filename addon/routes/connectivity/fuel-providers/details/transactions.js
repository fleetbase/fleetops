import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class FuelIntegrationTransactionsRoute extends Route {
    @service store;
    queryParams = Object.fromEntries(['page', 'limit', 'sort', 'query', 'sync_status', 'vehicle', 'transaction_at'].map((key) => [key, { refreshModel: true }]));
    model(params) {
        const connection = this.modelFor('connectivity.fuel-providers.details');
        return this.store.query('fuel-provider-transaction', { ...params, connection: connection.id });
    }
    @action refreshFuelTransactions() {
        this.refresh();
    }
}
