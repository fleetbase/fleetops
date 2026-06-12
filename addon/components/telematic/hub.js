import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

const WARNING_STATUSES = ['error', 'degraded', 'disconnected'];

export default class TelematicHubComponent extends Component {
    @service telematicActions;
    @service hostRouter;
    @tracked table;
    @tracked columns = this.args.columns ?? [];

    get integrations() {
        return Array.from(this.args.integrations ?? []);
    }

    get providers() {
        return Array.from(this.args.providers ?? []);
    }

    get connectedProviderKeys() {
        return new Set(this.integrations.map((integration) => integration.provider).filter(Boolean));
    }

    get hasProviderConnections() {
        return this.integrations.length > 0;
    }

    get providerCards() {
        const connected = this.connectedProviderKeys;

        return this.providers.map((provider) => {
            const integration = this.integrations.find((record) => record.provider === provider.key);

            return {
                ...provider,
                connected: connected.has(provider.key),
                integration,
            };
        });
    }

    get activeProviderCount() {
        return this.integrations.filter((integration) => !['disabled', 'archived'].includes(integration.status)).length;
    }

    get syncedDeviceCount() {
        return this.integrations.reduce((total, integration) => total + Number(integration.meta?.last_sync_total ?? 0), 0);
    }

    get unattachedDeviceCount() {
        return this.integrations.reduce((total, integration) => total + Number(integration.meta?.unattached_devices_count ?? 0), 0);
    }

    get healthWarningCount() {
        return this.integrations.filter((integration) => WARNING_STATUSES.includes(integration.status) || integration.meta?.last_error || integration.meta?.last_sync_error).length;
    }

    get kpiWidgets() {
        return [
            {
                icon: 'plug',
                label: 'Provider count',
                value: this.activeProviderCount,
                help: 'Connected provider accounts',
                action: 'Connect a provider',
                routeAction: 'create',
                accent: 'blue',
                accentClass: 'fleetops-connectivity-kpi-accent-blue',
            },
            {
                icon: 'satellite-dish',
                label: 'Synced Devices',
                value: this.syncedDeviceCount,
                help: 'Discovered from providers',
                action: 'Review synced devices',
                routeAction: 'devices',
                accent: 'green',
                accentClass: 'fleetops-connectivity-kpi-accent-green',
            },
            {
                icon: 'truck',
                label: 'Unattached devices',
                value: this.unattachedDeviceCount,
                help: this.unattachedDeviceCount > 0 ? 'Devices need vehicles' : 'No open attachment count',
                action: 'Attach to vehicles',
                routeAction: 'attachments',
                accent: 'amber',
                accentClass: 'fleetops-connectivity-kpi-accent-amber',
            },
            {
                icon: 'wave-square',
                label: 'Health warnings',
                value: this.healthWarningCount,
                help: this.healthWarningCount > 0 ? 'Warnings need review' : 'No warnings',
                action: 'Review health',
                routeAction: 'health',
                accent: 'rose',
                accentClass: 'fleetops-connectivity-kpi-accent-rose',
            },
        ];
    }

    statusForProvider(provider) {
        if (provider.connected) {
            return provider.integration?.status ?? 'connected';
        }

        return 'not-connected';
    }

    @action openProvider(provider) {
        if (provider.integration) {
            return this.telematicActions.transition.view(provider.integration);
        }

        return this.telematicActions.transition.create(provider.key);
    }

    @action refresh() {
        return this.telematicActions.refresh();
    }

    @action setupTable(table) {
        this.table = table;
    }

    @action runKpiAction(widget) {
        if (widget.routeAction === 'create') {
            return this.telematicActions.transition.create();
        }

        const integration = this.integrations[0];

        if (!integration) {
            return this.telematicActions.transition.create();
        }

        return this.telematicActions.transition.view(integration);
    }
}
