import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { loadTransactionOrder } from '../../../../utils/fuel-transaction';

export default class ManagementFuelTransactionsIndexDetailsRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;

    @action error(error) {
        this.notifications.serverError(error);
        return this.hostRouter.transitionTo('console.fleet-ops.management.fuel-transactions.index');
    }

    async model({ public_id }) {
        // URLs use public IDs; the store's primary key is the API UUID.
        const transaction = await this.store.queryRecord('fuel-provider-transaction', { public_id, single: true });

        try {
            await loadTransactionOrder(this.store, transaction);
        } catch (error) {
            this.notifications.serverError(error);
        }

        return transaction;
    }
}
