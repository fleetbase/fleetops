import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debug } from '@ember/debug';
import { timeout, task } from 'ember-concurrency';
import contextComponentCallback from '@fleetbase/ember-core/utils/context-component-callback';

export default class FleetDriverListingComponent extends Component {
    @service store;
    @service fetch;
    @service intl;
    @service universe;
    @service notifications;
    @tracked selected = [];
    @tracked selectable = false;
    @tracked drivers = [];
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
            const drivers = yield this.store.query('driver', { fleet: this.fleet.id, ...params });
            this.drivers = drivers.toArray();
            contextComponentCallback(this, 'onLoaded', drivers);
            return drivers;
        } catch (err) {
            debug('Unable to load fleet drivers: ' + err.message);
        }
    }

    @action onInput({ target: { value } }) {
        this.search.perform({ query: value });
    }

    @action async onAddDriver(driver) {
        try {
            await this.fetch.post('fleets/assign-driver', { driver: driver.id, fleet: this.fleet.id });
            this.drivers.pushObject(driver);
            this.universe.trigger('fleet-ops.fleet.driver_assigned', this.fleet, driver);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action async onRemoveDriver(driver) {
        try {
            await this.fetch.post('fleets/remove-driver', { driver: driver.id, fleet: this.fleet.id });
            this.drivers.removeObject(driver);
            this.universe.trigger('fleet-ops.fleet.driver_unassigned', this.fleet, driver);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action onSelect(driver) {
        if (this.selected.includes(driver)) {
            this.selected.removeObject(driver);
        } else {
            this.selected.pushObject(driver);
        }

        contextComponentCallback(this, 'onSelect', ...arguments);
    }
}
