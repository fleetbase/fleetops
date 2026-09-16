import Route from '@ember/routing/route';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class ManagementFuelTransactionsIndexRoute extends Route {
    @service store;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        provider: { refreshModel: true },
        sync_status: { refreshModel: true },
        vehicle: { refreshModel: true },
        connection: { refreshModel: true },
        transaction_at: { refreshModel: true },
    };

    @action refreshTransactions() {
        this.refresh();
    }

    model(params) {
        return this.store.query('fuel-provider-transaction', { sort: '-transaction_at', ...params });
    }
}
