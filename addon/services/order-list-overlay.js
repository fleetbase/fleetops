import Service from '@ember/service';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { isEmpty } from '@ember/utils';
import { task, timeout } from 'ember-concurrency';
import { A } from '@ember/array';
import { all } from 'rsvp';

/**
 * Service for managing the Order List Overlay programmatically.
 * Provides centralized state management and business logic for the overlay.
 *
 * @class OrderListOverlayService
 * @extends Service
 */
export default class OrderListOverlayService extends Service {
    @service store;
    @service fetch;
    @service appCache;
    @service hostRouter;
    @service notifications;
    @service abilities;
    @service urlSearchParams;

    /**
     * Tracks whether the overlay is currently open
     * @type {Boolean}
     */
    @tracked isOpen = false;

    /**
     * Tracks whether data has been loaded at least once
     * @type {Boolean}
     */
    @tracked loaded = false;

    /**
     * Current search query
     * @type {String}
     */
    @tracked query = '';

    /**
     * Array of currently selected orders
     * @type {Array}
     */
    @tracked selectedOrders = A([]);

    /**
     * Grouped orders by status
     * @type {Object}
     */
    @tracked orderGroups = {
        activeOrders: A([]),
        unassignedOrders: A([]),
    };

    /**
     * Array of fleets with their drivers
     * @type {Array}
     */
    @tracked fleets = A([]);

    /**
     * Error state for better error handling
     * @type {String|null}
     */
    @tracked error = null;

    /**
     * Callbacks for external event handlers
     * @type {Object}
     */
    callbacks = {};

    /**
     * Computed property to check if there are any selected orders
     * @type {Boolean}
     */
    get hasSelection() {
        return this.selectedOrders.length > 0;
    }

    /**
     * Computed property to check if the overlay is empty (no orders or fleets)
     * @type {Boolean}
     */
    get isEmpty() {
        return this.orderGroups.activeOrders.length === 0 && this.orderGroups.unassignedOrders.length === 0 && this.fleets.length === 0;
    }

    /**
     * Computed property to check if data is currently loading
     * @type {Boolean}
     */
    get isLoading() {
        return this.loadData.isRunning;
    }

    /**
     * Opens the overlay and loads data if not already loaded
     * @method open
     * @public
     */
    @action open() {
        this.isOpen = true;
        this.error = null;
        this.urlSearchParams.addParamToCurrentUrl('orderPanelOpen', 1);

        if (!this.loaded) {
            this.loadData.perform();
        }
    }

    /**
     * Closes the overlay
     * @method close
     * @public
     */
    @action close() {
        this.isOpen = false;
        this.urlSearchParams.removeParamFromCurrentUrl('orderPanelOpen');
    }

    /**
     * Toggles the overlay open/closed state
     * @method toggle
     * @public
     */
    @action toggle() {
        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
    }

    /**
     * Refreshes all data
     * @method refresh
     * @public
     */
    @action refresh() {
        this.error = null;
        return this.loadData.perform();
    }

    /**
     * Updates the search query with debouncing
     * @method search
     * @param {String} query - The search query
     * @public
     */
    @action search(query) {
        this.query = query;
        this.searchTask.perform(query);
    }

    /**
     * Toggles selection of an order
     * @method selectOrder
     * @param {Object} order - The order to select/deselect
     * @public
     */
    @action selectOrder(order) {
        if (this.selectedOrders.includes(order)) {
            this.selectedOrders.removeObject(order);
        } else {
            this.selectedOrders.pushObject(order);
        }
    }

    /**
     * Clears all selected orders
     * @method clearSelection
     * @public
     */
    @action clearSelection() {
        this.selectedOrders.clear();
    }

    /**
     * Navigates to order detail view
     * @method viewOrder
     * @param {Object} order - The order to view
     * @public
     */
    @action viewOrder(order) {
        return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index.view', order);
    }

    /**
     * Registers a callback for a specific action
     * @method on
     * @param {String} actionName - The action name
     * @param {Function} callback - The callback function
     * @public
     */
    @action on(actionName, callback) {
        this.callbacks[actionName] = callback;
    }

    /**
     * Triggers a registered callback
     * @method trigger
     * @param {String} actionName - The action name
     * @param {...any} args - Arguments to pass to the callback
     * @public
     */
    @action trigger(actionName, ...args) {
        const callback = this.callbacks[actionName];
        if (typeof callback === 'function') {
            return callback(...args);
        }
    }

    /**
     * Initializes the service and checks URL params
     * @method initialize
     * @public
     */
    @action initialize() {
        if (this.urlSearchParams.get('orderPanelOpen')) {
            this.open();
        }
    }

    /**
     * Task for searching orders with debouncing
     * @task searchTask
     * @private
     */
    @task *searchTask(query) {
        // Debounce search by 300ms
        yield timeout(300);

        // TODO: Implement search filtering logic
        // For now, this just debounces the query update
        // In a full implementation, you would filter orderGroups and fleets
        // based on the query string
    }

    /**
     * Main task for loading all data
     * @task loadData
     * @private
     */
    @task *loadData() {
        this.error = null;

        try {
            // Load all data in parallel for better performance
            yield all([this.loadFleets.perform(), this.loadUnassignedOrders.perform(), this.loadActiveOrders.perform()]);

            this.loaded = true;
        } catch (error) {
            this.error = 'Failed to load order data. Please try again.';
            this.notifications.serverError(error);
        }
    }

    /**
     * Task for loading fleets with their drivers and jobs
     * @task loadFleets
     * @private
     */
    @task *loadFleets() {
        if (this.abilities.cannot('fleet-ops list fleet')) {
            return;
        }

        // Get orders which are already loaded to exclude from reloading
        const activeLoadedOrders = this.getLoadedOrders();

        try {
            let fleets = yield this.store.query('fleet', {
                excludeDriverJobs: activeLoadedOrders.map((_) => _.public_id),
                with: ['serviceArea', 'drivers.jobs', 'drivers.currentJob'],
                without: ['drivers.fleets'],
            });

            // Reset loaded jobs to drivers
            if (isArray(fleets)) {
                fleets = fleets.map((fleet) => {
                    fleet.drivers = fleet.drivers.map((driver) => {
                        driver.set('orderPanelActiveJobs', [...driver.activeJobs, ...this.getLoadedActiveOrderForDriver(driver)]);
                        return driver;
                    });

                    return fleet;
                });

                this.fleets = A(fleets);
                this.appCache.setEmberData('fleets', fleets);
            }
        } catch (error) {
            this.notifications.serverError(error);
            throw error;
        }
    }

    /**
     * Task for loading unassigned orders
     * @task loadUnassignedOrders
     * @private
     */
    @task *loadUnassignedOrders() {
        if (this.abilities.cannot('fleet-ops list order')) {
            return;
        }

        // Get orders which are already loaded to exclude from reloading
        const activeLoadedOrders = this.getLoadedOrders();

        try {
            const unassignedOrders = yield this.fetch.get(
                'fleet-ops/live/orders',
                {
                    unassigned: 1,
                    exclude: activeLoadedOrders.map((_) => _.public_id),
                },
                {
                    normalizeToEmberData: true,
                    normalizeModelType: 'order',
                    expirationInterval: 5,
                    expirationIntervalUnit: 'minute',
                }
            );

            this.orderGroups = {
                ...this.orderGroups,
                unassignedOrders: A([...unassignedOrders, ...this.getLoadedUnassignedOrder()]),
            };
        } catch (error) {
            this.notifications.serverError(error);
            throw error;
        }
    }

    /**
     * Task for loading active orders
     * @task loadActiveOrders
     * @private
     */
    @task *loadActiveOrders() {
        if (this.abilities.cannot('fleet-ops list order')) {
            return;
        }

        // Get orders which are already loaded to exclude from reloading
        const activeLoadedOrders = this.getLoadedOrders();

        try {
            const serverActiveOrders = yield this.fetch.get(
                'fleet-ops/live/orders',
                {
                    active: 1,
                    with_tracker_data: 1,
                    exclude: activeLoadedOrders.map((_) => _.public_id),
                },
                {
                    normalizeToEmberData: true,
                    normalizeModelType: 'order',
                    expirationInterval: 5,
                    expirationIntervalUnit: 'minute',
                }
            );

            const activeOrders = A([...serverActiveOrders, ...this.getLoadedActiveOrder()]);

            // Load tracker data for orders that don't have it
            for (let order of activeOrders) {
                if (!order.get('tracker_data')) {
                    order.loadTrackerData();
                }
            }

            this.orderGroups = {
                ...this.orderGroups,
                activeOrders,
            };
        } catch (error) {
            this.notifications.serverError(error);
            throw error;
        }
    }

    /**
     * Gets all loaded orders from the store with optional filtering
     * @method getLoadedOrders
     * @param {Function|null} filter - Optional filter function
     * @returns {Array} Filtered orders
     * @private
     */
    getLoadedOrders(filter = null) {
        filter =
            typeof filter === 'function'
                ? filter
                : function () {
                      return true;
                  };

        const loadedOrders = this.store.peekAll('order');
        return loadedOrders.filter(filter);
    }

    /**
     * Gets loaded unassigned orders
     * @method getLoadedUnassignedOrder
     * @returns {Array} Unassigned orders
     * @private
     */
    getLoadedUnassignedOrder() {
        return this.getLoadedOrders((order) => {
            return isEmpty(order.driver_assigned_uuid);
        });
    }

    /**
     * Gets loaded active orders
     * @method getLoadedActiveOrder
     * @returns {Array} Active orders
     * @private
     */
    getLoadedActiveOrder() {
        return this.getLoadedOrders((order) => {
            return !isEmpty(order.driver_assigned) && !['created', 'completed', 'canceled', 'expired'].includes(order.status);
        });
    }

    /**
     * Gets loaded active orders for a specific driver
     * @method getLoadedActiveOrderForDriver
     * @param {Object} driver - The driver object
     * @returns {Array} Active orders for the driver
     * @private
     */
    getLoadedActiveOrderForDriver(driver) {
        return this.getLoadedOrders((order) => {
            return !isEmpty(order.driver_assigned) && order.driver_assigned.id === driver.id && !['created', 'completed', 'canceled', 'expired'].includes(order.status);
        });
    }
}
