import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ConnectivityTelematicsIndexDetailsController extends Controller {
    @service hostRouter;
    @service fetch;
    @service modalsManager;
    @service notifications;

    get tabs() {
        return [
            {
                route: 'connectivity.telematics.index.details.index',
                label: 'Overview',
            },
            {
                route: 'connectivity.telematics.index.details.devices',
                label: 'Devices',
            },
            {
                route: 'connectivity.telematics.index.details.attachments',
                label: 'Vehicle Attachments',
            },
            {
                route: 'connectivity.telematics.index.details.sensors',
                label: 'Sensors',
            },
            {
                route: 'connectivity.telematics.index.details.events',
                label: 'Events',
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
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.connectivity.telematics.index.edit', this.model),
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
        if (this.model?.meta?.last_error || this.model?.meta?.last_sync_error || ['error', 'degraded', 'disconnected'].includes(this.model?.status)) {
            return 'warning';
        }

        if (['active', 'connected'].includes(this.model?.status)) {
            return 'success';
        }

        return 'default';
    }

    get statusLabel() {
        if (this.healthStatus === 'success') {
            return 'Healthy';
        }

        if (this.healthStatus === 'warning') {
            return 'Needs attention';
        }

        return 'Not verified';
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
