import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class ConnectivityTelematicsIndexDetailsController extends Controller {
    @service hostRouter;
    @service fetch;
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
                route: 'connectivity.telematics.index.details.sensors',
                label: 'Sensors',
            },
            {
                route: 'connectivity.telematics.index.details.events',
                label: 'Events',
            },
        ];
    }

    get actionButtons() {
        return [
            {
                icon: 'plug',
                text: 'Test',
                onClick: () => this.testConnection.perform(),
                isLoading: this.testConnection.isRunning,
            },
            {
                icon: 'satellite-dish',
                text: 'Discover',
                onClick: () => this.discoverDevices.perform(),
                isLoading: this.discoverDevices.isRunning,
            },
            {
                icon: 'pencil',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.connectivity.telematics.index.edit', this.model),
            },
        ];
    }

    get telematicId() {
        return this.model?.id ?? this.model?.public_id ?? this.model?.uuid;
    }

    @task *testConnection() {
        try {
            const result = yield this.fetch.post(`telematics/${this.telematicId}/test-connection`);
            if (result.success) {
                this.notifications.success(result.message ?? 'Connection successful.');
            } else {
                this.notifications.error(result.message ?? 'Connection test failed.');
            }
            yield this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
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
