import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * Effective-dated towing history for a trailer, newest first. The active
 * connection is highlighted and can be ended from here.
 */
export default class TrailerDetailsConnectionsComponent extends Component {
    @service store;
    @service notifications;
    @service trailerActions;
    @service vehicleActions;
    @tracked connections = [];

    get trailer() {
        return this.args.resource;
    }

    get isAttached() {
        return this.trailer?.isAttached ?? this.trailer?.attachment_state === 'attached';
    }

    constructor() {
        super(...arguments);
        this.loadConnections.perform();
    }

    @task *loadConnections() {
        try {
            const trailer = yield this.store.queryRecord('trailer', { public_id: this.trailer.public_id ?? this.trailer.id, single: true, with: ['connections.vehicle'] });
            this.connections = Array.from(trailer?.connections ?? []);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action viewVehicle(vehicle) {
        if (!vehicle) {
            return;
        }

        if (this.vehicleActions.panel?.view) {
            return this.vehicleActions.panel.view(vehicle);
        }

        return this.vehicleActions.transition.view(vehicle);
    }

    @action attachVehicle() {
        return this.trailerActions.attachVehicle(this.trailer, { callback: () => this.loadConnections.perform() });
    }

    @action detachVehicle() {
        return this.trailerActions.detachVehicle(this.trailer, { callback: () => this.loadConnections.perform() });
    }
}
