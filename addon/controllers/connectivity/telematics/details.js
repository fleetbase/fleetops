import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ConnectivityTelematicsDetailsController extends Controller {
    @service hostRouter;
    @service fetch;
    @service modalsManager;
    @service notifications;

    get tabs() {
        return [
            {
                route: 'connectivity.telematics.details.index',
                label: 'Overview',
            },
            {
                route: 'connectivity.telematics.details.devices',
                label: 'Devices',
            },
            {
                route: 'connectivity.telematics.details.attachments',
                label: 'Vehicle Attachments',
            },
            {
                route: 'connectivity.telematics.details.sensors',
                label: 'Sensors',
            },
            {
                route: 'connectivity.telematics.details.events',
                label: 'Events',
            },
            {
                route: 'connectivity.telematics.details.logs',
                label: 'Logs',
            },
        ].map((tab) => ({
            ...tab,
            active: this.isTabActive(tab.route),
        }));
    }

    get actionButtons() {
        return [
            {
                icon: 'plug',
                text: 'Test',
                onClick: () => this.openConnectionTestDialog(),
            },
            {
                icon: 'satellite-dish',
                text: 'Discover',
                onClick: () => this.discoverDevices.perform(),
                isLoading: this.discoverDevices.isRunning,
            },
            {
                icon: 'cog',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.connectivity.telematics.edit', this.model),
            },
        ];
    }

    get telematicId() {
        return this.model?.id;
    }

    isTabActive(routeName) {
        return this.hostRouter.currentRouteName?.endsWith(routeName);
    }

    get healthStatus() {
        switch (this.model?.status) {
            case 'active':
            case 'connected':
                return 'success';
            case 'synchronizing':
                return 'info';
            case 'error':
            case 'degraded':
            case 'disconnected':
                return 'warning';
            case 'initialized':
                return 'default';
            default:
                return 'default';
        }
    }

    get statusLabel() {
        switch (this.model?.status) {
            case 'initialized':
                return 'Not tested';
            case 'connected':
                return 'Connected';
            case 'synchronizing':
                return 'Syncing';
            case 'active':
                return 'Connected';
            case 'error':
                return 'Needs attention';
            case null:
            case undefined:
                return 'Unknown';
            default:
                return 'Unknown';
        }
    }

    get connectionTestLabel() {
        switch (this.model?.meta?.last_test_result) {
            case 'success':
                return 'Verified';
            case 'failed':
                return 'Failed';
            default:
                return 'Not tested';
        }
    }

    get lastSyncAt() {
        if (this.model?.status === 'synchronizing') {
            return this.model?.meta?.last_sync_started_at;
        }

        return this.model?.meta?.last_sync_completed_at;
    }

    @action openConnectionTestDialog() {
        this.modalsManager.show('modals/telematic-connection-diagnostics', {
            title: 'Test Connection',
            acceptButtonText: 'Run Test',
            acceptButtonIcon: 'plug',
            declineButtonText: 'Close',
            telematic: this.model,
            onTested: () => this.hostRouter.refresh(),
        });
    }

    @task *discoverDevices() {
        try {
            const result = yield this.fetch.post(`telematics/${this.telematicId}/discover`);
            this.notifications.success(result.message ?? 'Device discovery initiated.');
            yield this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
