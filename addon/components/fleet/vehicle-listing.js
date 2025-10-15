import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debug } from '@ember/debug';
import { timeout, task } from 'ember-concurrency';
import contextComponentCallback from '@fleetbase/ember-core/utils/context-component-callback';

export default class FleetVehicleListingComponent extends Component {
    @service store;
    @service fetch;
    @service intl;
    @service universe;
    @tracked vehicles = [];
    @tracked selected = [];
    @tracked selectable = false;
    @tracked fleet;

    constructor() {
        super(...arguments);
        const { options = {} } = this.args;

        this.fleet = this.args.resource;
        this.selectable = this.args.selectable === true || options.selectable === true;
        this.search.perform({ limit: -1 });
    }

    @task({ restartable: true }) *search(params = {}) {
        if (!params.value) {
            yield timeout(300);
        }

        try {
            const vehicles = yield this.store.query('vehicle', { fleet: this.fleet.id, ...params });
            this.vehicles = vehicles.toArray();
            contextComponentCallback(this, 'onLoaded', vehicles);
            return vehicles;
        } catch (err) {
            debug('Unable to load fleet vehicles: ' + err.message);
        }
    }

    @action onInput({ target: { value } }) {
        this.search.perform({ query: value });
    }

    @action async onAddVehicle(vehicle) {
        try {
            await this.fetch.post('fleets/assign-vehicle', { vehicle: vehicle.id, fleet: this.fleet.id });
            this.vehicles.pushObject(vehicle);
            this.universe.trigger('fleet-ops.fleet.vehicle_assigned', this.fleet, vehicle);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action async onRemoveVehicle(vehicle) {
        try {
            await this.fetch.post('fleets/remove-vehicle', { vehicle: vehicle.id, fleet: this.fleet.id });
            this.vehicles.removeObject(vehicle);
            this.universe.trigger('fleet-ops.fleet.vehicle_unassigned', this.fleet, vehicle);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action onSelect(vehicle) {
        if (this.selected.includes(vehicle)) {
            this.selected.removeObject(vehicle);
        } else {
            this.selected.pushObject(vehicle);
        }

        contextComponentCallback(this, 'onSelect', ...arguments);
    }
}
