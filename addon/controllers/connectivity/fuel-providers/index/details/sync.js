import Controller from '@ember/controller';
import { inject as controller } from '@ember/controller';

export default class ConnectivityFuelProvidersIndexDetailsSyncController extends Controller {
    @controller('connectivity.fuel-providers.index.details') details;

    get syncTransactions() {
        return this.details.syncTransactions;
    }

    get lastSyncStateJson() {
        return JSON.stringify(this.model?.connection?.last_sync_state ?? {}, null, 2);
    }

    get syncRuns() {
        return Array.from(this.model?.syncRuns ?? []);
    }
}
