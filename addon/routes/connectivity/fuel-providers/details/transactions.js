import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class FuelIntegrationTransactionsRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops list fuel-provider-transaction')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.connectivity.fuel-providers.index');
        }
    }

    queryParams = Object.fromEntries(['page', 'limit', 'sort', 'query', 'sync_status', 'vehicle', 'transaction_at'].map((key) => [key, { refreshModel: true }]));
    model(params) {
        const connection = this.modelFor('connectivity.fuel-providers.details');
        return this.store.query('fuel-provider-transaction', { ...params, connection: connection.id });
    }
    @action refreshFuelTransactions() {
        this.refresh();
    }
}
