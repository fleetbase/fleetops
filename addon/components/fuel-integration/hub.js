import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

import { fuelDate, fuelNumber, fuelMoney } from '../../utils/fuel-integration-format';

const WARNING_STATUSES = ['error', 'disabled'];

export default class FuelIntegrationHubComponent extends Component {
    @service fuelIntegrationActions;
    @service currentUser;
    @tracked table;
    @tracked columns = this.args.columns ?? [];

    get connections() {
        return Array.from(this.args.connections ?? []);
    }

    get providers() {
        return Array.from(this.args.providers ?? []);
    }

    get hasConnections() {
        return this.connections.length > 0;
    }

    get connectedProviderKeys() {
        return new Set(this.connections.map((connection) => connection.provider).filter(Boolean));
    }

    get providerCards() {
        return this.providers.map((provider) => {
            const connection = this.connections.find((record) => record.provider === provider.key);

            return {
                ...provider,
                connection,
                connected: Boolean(connection),
                status: connection?.status ?? 'available',
                categoryLabel: provider.category ?? provider.metadata?.category ?? 'Fuel card integration',
            };
        });
    }

    get activeConnectionCount() {
        return this.connections.filter((connection) => !['disabled', 'archived'].includes(connection.status)).length;
    }

    get unmatchedTransactionsCount() {
        return this.connections.reduce((total, connection) => total + Number(connection.last_sync_state?.summary?.unmatched ?? 0), 0);
    }

    get syncErrorCount() {
        return this.connections.filter((connection) => WARNING_STATUSES.includes(connection.status) || connection.last_error).length;
    }

    get totalLiters() {
        return this.connections.reduce((total, connection) => total + Number(connection.last_sync_state?.summary?.liters ?? 0), 0);
    }

    get totalSpend() {
        return this.connections.reduce((total, connection) => total + Number(connection.last_sync_state?.summary?.amount ?? 0), 0);
    }

    get lastSyncLabel() {
        const dates = this.connections
            .map((connection) => connection.last_synced_at)
            .filter(Boolean)
            .map((value) => new Date(value).getTime())
            .filter(Number.isFinite);
        return dates.length ? fuelDate(Math.max(...dates)) : 'Not synced';
    }

    get kpiWidgets() {
        return [
            {
                icon: 'gas-pump',
                label: 'Connected integrations',
                value: this.activeConnectionCount,
                help: 'Fuel card and fuel management accounts',
                action: 'Connect integration',
                actionType: 'create',
                accentClass: 'fleetops-connectivity-kpi-accent-blue',
            },
            {
                icon: 'clock-rotate-left',
                label: 'Last sync',
                value: this.lastSyncLabel,
                help: 'Most recent completed import',
                action: 'Review sync',
                actionType: 'review',
                accentClass: 'fleetops-connectivity-kpi-accent-green',
            },
            {
                icon: 'triangle-exclamation',
                label: 'Unmatched in latest runs',
                value: this.unmatchedTransactionsCount,
                help: this.unmatchedTransactionsCount > 0 ? 'Need vehicle or trip matching' : 'No unmatched count',
                action: 'Review matches',
                actionType: 'transactions',
                accentClass: 'fleetops-connectivity-kpi-accent-amber',
            },
            {
                icon: 'receipt',
                label: 'Latest import spend',
                value: this.formatSpend,
                help: `${this.formatLiters} imported`,
                action: 'Open ledger',
                actionType: 'transactions',
                accentClass: 'fleetops-connectivity-kpi-accent-rose',
            },
        ];
    }

    get formatSpend() {
        const currencies = new Map();
        for (const connection of this.connections) {
            const currency = connection.last_sync_state?.summary?.currency || (connection.provider === 'petroapp' ? 'SAR' : null);
            if (currency) currencies.set(currency, (currencies.get(currency) || 0) + Number(connection.last_sync_state?.summary?.amount || 0));
        }
        return [...currencies].map(([currency, amount]) => fuelMoney(amount, currency)).join(' · ') || 'No spend recorded';
    }

    get formatLiters() {
        return `${fuelNumber(this.totalLiters)} L`;
    }

    @action openProvider(provider) {
        if (provider.connection) {
            return this.fuelIntegrationActions.transition.view(provider.connection);
        }

        return this.fuelIntegrationActions.transition.create(provider.key);
    }

    @action createIntegration() {
        return this.fuelIntegrationActions.transition.create();
    }

    @action runKpiAction(widget) {
        if (widget.actionType === 'create') {
            return this.fuelIntegrationActions.transition.create();
        }

        if (widget.actionType === 'transactions') {
            return this.fuelIntegrationActions.transitionTo('management.fuel-transactions.index');
        }

        const connection = this.connections[0];
        if (connection) {
            return this.fuelIntegrationActions.transitionTo('connectivity.fuel-providers.details.sync', connection);
        }

        return this.fuelIntegrationActions.transition.create();
    }

    @action setupTable(table) {
        this.table = table;
    }
}
