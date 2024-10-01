import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import contextComponentCallback from '@fleetbase/ember-core/utils/context-component-callback';

export default class OrderListOverlayComponent extends Component {
    @service store;
    @service fetch;
    @service appCache;
    @service router;
    @service hostRouter;
    @service notifications;
    @service abilities;
    @tracked fleets = [];
    @tracked activeOrders = [];
    @tracked unassignedOrders = [];
    @tracked selectedOrders = [];
    @tracked overlayContext;
    @tracked query = null;

    @action onLoad(overlayContext) {
        this.overlayContext = overlayContext;

        if (typeof this.args.onLoad === 'function') {
            this.args.onLoad(...arguments);
        }
    }

    @action onToggle() {
        this.loadFleets.perform();
        this.loadUnassignedOrders.perform();
        this.loadActiveOrders.perform();
    }

    @action selectOrder(order) {
        if (this.selectedOrders.includes(order)) {
            this.selectedOrders.removeObject(order);
        } else {
            this.selectedOrders.pushObject(order);
        }
    }

    @action viewOrder(order) {
        const router = this.router ?? this.hostRouter;

        return router.transitionTo('console.fleet-ops.operations.orders.index.view', order);
    }

    @action onAction(actionName, ...params) {
        contextComponentCallback(this, actionName, ...params, this);
    }

    @task *loadFleets() {
        if (this.abilities.cannot('fleet-ops list fleet')) {
            return;
        }

        try {
            this.fleets = yield this.store.query('fleet', { with: ['serviceArea', 'drivers.jobs', 'drivers.currentJob'], without: ['drivers.fleets'] });
            this.appCache.setEmberData('fleets', this.fleets);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *loadUnassignedOrders() {
        if (this.abilities.cannot('fleet-ops list order')) {
            return;
        }

        try {
            this.unassignedOrders = yield this.store.query('order', { unassigned: 1 });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *loadActiveOrders() {
        if (this.abilities.cannot('fleet-ops list order')) {
            return;
        }

        // Get orders which are already loaded to exclude from reloading
        const loadedOrders = this.store.peekAll('order');
        const activeLoadedOrders = loadedOrders.filter((order) => {
            return order.hasActiveStatus && order.has_driver_assigned;
        });

        // Load live orders
        try {
            this.activeOrders = yield this.fetch.get(
                'fleet-ops/live/orders',
                {
                    exclude: activeLoadedOrders.map((_) => _.public_id),
                },
                {
                    normalizeToEmberData: true,
                    normalizeModelType: 'order',
                    expirationInterval: 5,
                    expirationIntervalUnit: 'minute',
                }
            );
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
