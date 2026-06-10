import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectivityFuelProvidersIndexDetailsSyncRoute extends Route {
    @service store;

    model() {
        const connection = this.modelFor('connectivity.fuel-providers.details');

        return {
            connection,
            syncRuns: this.store.query('fuel-provider-sync-run', {
                connection: connection.uuid,
                sort: '-created_at',
            }),
        };
    }
}
