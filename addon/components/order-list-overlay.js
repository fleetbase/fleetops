import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class OrderListOverlayComponent extends Component {
    @service store;
    @service fetch;
    @service appCache;
    @service router;
    @service hostRouter;
    @service notifications;
    @service abilities;

    /**
     * The loaded fleet records.
     *
     * @memberof OrderListOverlayComponent
     */
    @tracked fleets = [];

    /**
     * The loaded active order records.
     *
     * @memberof OrderListOverlayComponent
     */
    @tracked activeOrders = [];

    /**
     * The loaded unassigned order records.
     *
     * @memberof OrderListOverlayComponent
     */
    @tracked unassignedOrders = [];

    /**
     * The user selected order records.
     *
     * @memberof OrderListOverlayComponent
     */
    @tracked selectedOrders = [];

    /**
     * Search filter variable
     *
     * @memberof OrderListOverlayComponent
     */
    @tracked query = null;

    /**
     * Creates an instance of OrderListOverlayComponent.
     * @memberof OrderListOverlayComponent
     */
    constructor() {
        super(...arguments);

        this.loadFleets.perform();
        this.loadUnassignedOrders.perform();
        this.loadActiveOrders.perform();
    }

    /**
     * Toggles an order selection.
     *
     * @param {OrderModel} order
     * @memberof OrderListOverlayComponent
     */
    @action selectOrder(order) {
        if (this.selectedOrders.includes(order)) {
            this.selectedOrders.removeObject(order);
        } else {
            this.selectedOrders.pushObject(order);
        }
    }

    /**
     * Transitions to view the order.
     *
     * @param {OrderModel} order
     * @return {Transition<Promise>}
     * @memberof OrderListOverlayComponent
     */
    @action viewOrder(order) {
        const router = this.router ?? this.hostRouter;

        return router.transitionTo('console.fleet-ops.operations.orders.index.view', order);
    }

    /**
     * Triggers a component action.
     *
     * @param {String} actionName
     * @param {...} params
     * @memberof OrderListOverlayComponent
     */
    @action onAction(actionName, ...params) {
        params.pushObject(this);

        if (typeof this[actionName] === 'function') {
            this[actionName](...params);
        }

        if (typeof this.args[actionName] === 'function') {
            this.args[actionName](...params);
        }
    }

    /**
     * Triggers an action from the dropdown menu
     *
     * @param {DropdownActions} dd
     * @param {String} actionName
     * @param {...} params
     * @memberof OrderListOverlayComponent
     */
    @action onDropdownAction(dd, actionName, ...params) {
        if (typeof dd?.actions?.close === 'function') {
            dd.actions.close();
        }

        this.onAction(actionName, ...params);
    }

    /**
     * Load fleet records.
     */
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

    /**
     * Load unassigned order records.
     */
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

    /**
     * Load active order records.
     */
    @task *loadActiveOrders() {
        if (this.abilities.cannot('fleet-ops list order')) {
            return;
        }

        try {
            this.activeOrders = yield this.fetch.get(
                'fleet-ops/live/orders',
                {},
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
