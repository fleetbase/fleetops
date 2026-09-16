import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { syncRunView, fuelNumber, fuelMoney } from '../../../utils/fuel-integration-format';
import { task, timeout } from 'ember-concurrency';

export default class ConnectivityFuelProvidersIndexDetailsController extends Controller {
    @service hostRouter;
    @service fetch;
    @service notifications;
    @service modalsManager;

    @tracked activity;
    @tracked activityError;

    get runs() {
        return (this.activity?.runs ?? []).map(syncRunView);
    }
    get latestRun() {
        return this.runs[0];
    }
    get hasPendingSync() {
        return this.runs.some((run) => run.pending);
    }
    get syncDisabled() {
        return this.hasPendingSync || this.model?.status === 'disabled' || this.syncTransactions.isRunning;
    }
    get totals() {
        return this.activity?.totals;
    }
    get metrics() {
        if (!this.totals) return [];
        return [
            { label: 'Imported transactions', value: fuelNumber(this.totals.transactions), icon: 'receipt' },
            { label: 'Needs matching', value: fuelNumber(this.totals.unmatched), icon: 'link' },
            { label: 'Fuel reports', value: fuelNumber(this.totals.fuel_reports), icon: 'file-lines' },
            { label: 'Fuel volume', value: `${fuelNumber(this.totals.liters)} L`, icon: 'gas-pump' },
        ];
    }
    get spend() {
        return (this.totals?.spend ?? []).map((row) => fuelMoney(row.amount, row.currency)).join(' · ') || 'No spend recorded';
    }
    get environmentLabel() {
        return this.model?.environment === 'sandbox' ? 'Sandbox / test account' : 'Production account';
    }

    @action async refreshActivity() {
        try {
            const id = this.model.id;
            const activity = await this.fetch.get(`fuel-provider-connections/${id}/activity`);
            if (this.isDestroying || this.model.id !== id) return;
            this.activity = activity;
            this.activityError = null;
            const state = { ...activity.connection };
            for (const field of ['last_synced_at', 'last_tested_at']) state[field] = state[field] ? new Date(state[field]) : null;
            this.model.setProperties(state);
        } catch (error) {
            this.activityError = 'Unable to refresh integration activity. Your current view is preserved; try Refresh.';
        }
    }

    @task({ restartable: true }) *watchActivity() {
        do {
            yield this.refreshActivity();
            if (!this.hasPendingSync || this.activityError) return;
            yield timeout(3000);
        } while (!this.isDestroying);
    }

    get tabs() {
        return [
            { route: 'connectivity.fuel-providers.details.index', label: 'Overview' },
            { route: 'connectivity.fuel-providers.details.sync', label: 'Sync' },
            { route: 'connectivity.fuel-providers.details.matching', label: 'Matching' },
            { route: 'connectivity.fuel-providers.details.transactions', label: 'Transactions' },
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

    @action openConnectionTestDialog() {
        return this.modalsManager.show('modals/fuel-connection-diagnostics', {
            title: 'Test Fuel Connection',
            acceptButtonText: 'Run Test',
            acceptButtonIcon: 'plug',
            declineButtonText: 'Close',
            connection: this.model,
            onTested: this.refreshActivity,
        });
    }

    @task({ drop: true }) *syncTransactions(options = {}) {
        if (this.hasPendingSync || this.model?.status === 'disabled') return;
        try {
            yield this.fetch.post(`fuel-provider-connections/${this.model.id}/sync`, { async: true, from: options.from, to: options.to });
            this.notifications.success('Fuel transaction sync queued.');
            yield this.refreshActivity();
            this.watchActivity.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
