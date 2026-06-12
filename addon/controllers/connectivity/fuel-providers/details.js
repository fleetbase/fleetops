import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class ConnectivityFuelProvidersIndexDetailsController extends Controller {
    @service hostRouter;
    @service fetch;
    @service notifications;

    get tabs() {
        return [
            { route: 'connectivity.fuel-providers.details.index', label: 'Overview' },
            { route: 'connectivity.fuel-providers.details.sync', label: 'Sync' },
            { route: 'connectivity.fuel-providers.details.matching', label: 'Matching' },
            { route: 'connectivity.fuel-providers.details.transactions', label: 'Transactions' },
            { route: 'connectivity.fuel-providers.details.settings', label: 'Settings' },
        ].map((tab) => ({
            ...tab,
            active: this.hostRouter.currentRouteName?.endsWith(tab.route),
        }));
    }

    get statusLabel() {
        switch (this.model?.status) {
            case 'draft':
                return 'Draft';
            case 'configured':
                return 'Configured';
            case 'connected':
                return 'Connected';
            case 'active':
                return 'Active';
            case 'error':
                return 'Needs attention';
            case 'disabled':
                return 'Disabled';
            default:
                return 'Unknown';
        }
    }

    get healthStatus() {
        return ['connected', 'active'].includes(this.model?.status) ? 'success' : this.model?.status === 'error' ? 'warning' : 'default';
    }

    get lastSummary() {
        return this.model?.last_sync_state?.summary ?? {};
    }

    @task *testConnection() {
        try {
            const result = yield this.fetch.post(`fuel-provider-connections/${this.model.id}/test-connection`);
            this.notifications.success(result.message ?? 'Fuel integration connection tested.');
            yield this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *syncTransactions(options = {}) {
        try {
            yield this.fetch.post(`fuel-provider-connections/${this.model.id}/sync`, { async: true, ...options });
            this.notifications.success('Fuel transaction sync queued.');
            yield this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
