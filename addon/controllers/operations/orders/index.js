import BaseController from '@fleetbase/fleetops-engine/controllers/base-controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { equal } from '@ember/object/computed';
import { debug } from '@ember/debug';
import { isArray } from '@ember/array';
import { isBlank } from '@ember/utils';
import { timeout, task } from 'ember-concurrency';

export default class OperationsOrdersIndexController extends BaseController {
    @service currentUser;
    @service fetch;
    @service store;
    @service intl;
    @service filters;
    @service hostRouter;
    @service notifications;
    @service modalsManager;
    @service crud;
    @service universe;
    @service socket;
    @service abilities;
    @service theme;
    @service routeOptimization;

    /**
     * Queryable parameters for this controller's model
     *
     * @var {Array}
     */
    queryParams = [
        'page',
        'limit',
        'sort',
        'query',
        'public_id',
        'internal_id',
        'payload',
        'tracking_number',
        'facilitator',
        'customer',
        'driver',
        'vehicle',
        'pickup',
        'dropoff',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
        'scheduled_at',
        'status',
        'type',
        'without_driver',
        'bulk_query',
        'layout',
        'drawerOpen',
        'drawerTab',
        'orderPanelOpen',
    ];

    /**
     * The current driver being focused.
     *
     * @var {DriverModel|null}
     */
    @tracked focusedDriver;

    /**
     * The current vehicle being focused.
     *
     * @var {VehicleModel|null}
     */
    @tracked focusedVehicle;

    /**
     * The current page of data being viewed
     *
     * @var {Integer}
     */
    @tracked page = 1;

    /**
     * The maximum number of items to show per page
     *
     * @var {Integer}
     */
    @tracked limit;

    /**
     * The param to sort the data on, the param with prepended `-` is descending
     *
     * @var {String}
     */
    @tracked sort = '-created_at';

    /**
     * The filterable param `public_id`
     *
     * @var {String}
     */
    @tracked public_id;

    /**
     * The filterable param `internal_id`
     *
     * @var {String}
     */
    @tracked internal_id;

    /**
     * The filterable param `tracking`
     *
     * @var {String}
     */
    @tracked tracking;

    /**
     * The filterable param `facilitator`
     *
     * @var {String}
     */
    @tracked facilitator;

    /**
     * The filterable param `customer`
     *
     * @var {String}
     */
    @tracked customer;

    /**
     * The filterable param `driver`
     *
     * @var {String}
     */
    @tracked driver;

    /**
     * The filterable param `vehicle`
     *
     * @var {String}
     */
    @tracked vehicle;

    /**
     * The filterable param `payload`
     *
     * @var {String}
     */
    @tracked payload;

    /**
     * The filterable param `pickup`
     *
     * @var {String}
     */
    @tracked pickup;

    /**
     * The filterable param `dropoff`
     *
     * @var {String}
     */
    @tracked dropoff;

    /**
     * The filterable param `updated_by`
     *
     * @var {String}
     */
    @tracked updated_by;

    /**
     * The filterable param `created_by`
     *
     * @var {String}
     */
    @tracked created_by;

    /**
     * The filterable param `created_at`
     *
     * @var {String}
     */
    @tracked created_at;

    /**
     * The filterable param `updated_at`
     *
     * @var {String}
     */
    @tracked updated_at;

    /**
     * The filterable param `scheduled_at`
     *
     * @var {String}
     */
    @tracked scheduled_at;

    /**
     * The filterable param `without_driver`
     *
     * @var {String}
     */
    @tracked without_driver;

    /**
     * The filterable param `status`
     *
     * @var {String}
     */
    @tracked status;

    /**
     * The filterable param `type` - Filter by order type
     *
     * @var {String}
     */
    @tracked type;

    /**
     * Flag to determine if the search is visible
     *
     * @type {Boolean}
     */
    @tracked isSearchVisible = false;

    /**
     * Flag to determine if the orders panel is visible
     *
     * @type {Boolean}
     */
    @tracked isOrdersPanelVisible = false;

    /**
     * Count of active orders
     *
     * @type {Number}
     */
    @tracked activeOrdersCount = 0;

    /**
     * The context for the order list overlay panel.
     *
     * @type {Object}
     */
    @tracked orderListOverlayContext;

    /**
     * Reference to the leaflet map object
     *
     * @type {Object}
     */
    @tracked leafletMap;

    /**
     * Reference to the drawer context API.
     *
     * @type {Object}
     */
    @tracked drawer;

    /**
     * Current layout type (e.g., 'map', 'table', 'kanban', 'analytics')
     *
     * @type {String}
     */
    @tracked layout = 'map';

    /**
     * Decides if scope drawer is open.
     *
     * @type {Boolean}
     */
    @tracked drawerOpen = 0;

    /**
     * The drawer tab that is active.
     *
     * @type {Boolean}
     */
    @tracked drawerTab;

    /**
     * Filterable status options for orders.
     *
     * @type {Array}
     */
    @tracked statusOptions = [];

    /**
     * Filterable sorder configs.
     *
     * @type {Array}
     */
    @tracked orderConfigs = [];

    /**
     * Free text input for a bulk query.
     *
     * @type {String}
     */
    @tracked bulkSearchValue = '';

    /**
     * Actual bulk query.
     *
     * @type {String}
     */
    @tracked bulk_query = '';

    /**
     * Flag to determine if the layout is 'map'
     *
     * @type {Boolean}
     */
    @equal('layout', 'map') isMapLayout;

    /**
     * Flag to determine if the layout is 'table'
     *
     * @type {Boolean}
     */
    @equal('layout', 'table') isTableLayout;

    /**
     * Flag to determine if the view is 'kanban'
     *
     * @type {Boolean}
     */
    @equal('layout', 'kanban') isKanbanView;

    /**
     * Flag to determine if the layout is 'analytics'
     *
     * @type {Boolean}
     */
    @equal('layout', 'analytics') isAnalyticsLayout;

    /**
     * All columns applicable for orders
     *
     * @var {Array}
     */
    @tracked columns = [
        {
            label: this.intl.t('fleet-ops.common.id'),
            valuePath: 'public_id',
            width: '140px',
            cellComponent: 'table/cell/link-to',
            route: 'operations.orders.index.view',
            onLinkClick: this.viewOrder,
            permission: 'fleet-ops view order',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.internal-id'),
            valuePath: 'internal_id',
            cellComponent: 'click-to-copy',
            width: '125px',
            hidden: true,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.operations.orders.index.payload'),
            valuePath: 'payload.public_id',
            cellComponent: 'click-to-copy',
            resizable: true,
            hidden: true,
            width: '125px',
            filterable: true,
            filterLabel: 'Payload ID',
            filterParam: 'payload',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.operations.orders.index.driver-assigned'),
            cellComponent: 'cell/driver-name',
            valuePath: 'driver_assigned',
            modelPath: 'driver_assigned',
            width: '210px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select driver for order',
            filterParam: 'driver',
            model: 'driver',
        },
        {
            label: this.intl.t('fleet-ops.operations.orders.index.pickup'),
            valuePath: 'pickupName',
            cellComponent: 'table/cell/base',
            width: '160px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterOptionLabel: 'address',
            filterComponentPlaceholder: 'Select order pickup location',
            filterParam: 'pickup',
            modelNamePath: 'address',
            model: 'place',
        },
        {
            label: this.intl.t('fleet-ops.operations.orders.index.dropoff'),
            valuePath: 'dropoffName',
            cellComponent: 'table/cell/base',
            width: '160px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select order dropoff location',
            filterParam: 'dropoff',
            modelNamePath: 'address',
            model: 'place',
        },
        {
            label: this.intl.t('fleet-ops.operations.orders.index.customer'),
            valuePath: 'customer.name',
            cellComponent: 'table/cell/base',
            width: '125px',
            resizable: true,
            sortable: true,
            hidden: false,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select order customer',
            filterParam: 'customer',
            model: 'customer',
        },
        {
            label: this.intl.t('fleet-ops.operations.orders.index.vehicle-assigned'),
            cellComponent: 'cell/vehicle-name',
            valuePath: 'vehicle_assigned.display_name',
            modelPath: 'vehicle_assigned',
            showOnlineIndicator: true,
            width: '170px',
            hidden: true,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select vehicle for order',
            filterParam: 'vehicle',
            modelNamePath: 'display_name',
            model: 'vehicle',
        },
        {
            label: this.intl.t('fleet-ops.operations.orders.index.facilitator'),
            valuePath: 'facilitator.name',
            cellComponent: 'table/cell/base',
            width: '125px',
            resizable: true,
            hidden: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select order facilitator',
            filterParam: 'facilitator',
            model: 'vendor',
        },
        {
            label: this.intl.t('fleet-ops.operations.orders.index.scheduled-at'),
            valuePath: 'scheduledAt',
            sortParam: 'scheduled_at',
            filterParam: 'scheduled_at',
            width: '150px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: this.intl.t('fleet-ops.operations.orders.index.items'),
            cellComponent: 'table/cell/base',
            valuePath: 'item_count',
            resizable: true,
            hidden: true,
            width: '50px',
        },
        {
            label: this.intl.t('fleet-ops.operations.orders.index.transaction'),
            cellComponent: 'table/cell/base',
            valuePath: 'transaction_amount',
            width: '50px',
            resizable: true,
            hidden: true,
            sortable: true,
        },
        {
            label: this.intl.t('fleet-ops.operations.orders.index.tracking'),
            valuePath: 'tracking_number.tracking_number',
            cellComponent: 'click-to-copy',
            width: '170px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.type'),
            valuePath: 'type',
            width: '100px',
            resizable: true,
            hidden: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/select',
            filterOptions: this.orderConfigs,
            filterOptionLabel: 'name',
            filterOptionValue: 'id',
            filterComponentPlaceholder: 'Filter by order config',
        },
        {
            label: this.intl.t('fleet-ops.common.status'),
            valuePath: 'status',
            cellComponent: 'table/cell/status',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/multi-option',
            filterOptions: this.statusOptions,
        },
        {
            label: this.intl.t('fleet-ops.common.created-at'),
            valuePath: 'createdAtShort',
            sortParam: 'created_at',
            filterParam: 'created_at',
            width: '140px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: this.intl.t('fleet-ops.common.updated-at'),
            valuePath: 'updatedAtShort',
            sortParam: 'updated_at',
            filterParam: 'updated_at',
            width: '125px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: this.intl.t('fleet-ops.operations.orders.index.created-by'),
            valuePath: 'created_by_name',
            width: '125px',
            resizable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select user',
            filterParam: 'created_by',
            model: 'user',
        },
        {
            label: this.intl.t('fleet-ops.operations.orders.index.updated-by'),
            valuePath: 'updated_by_name',
            width: '125px',
            resizable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select user',
            filterParam: 'updated_by',
            model: 'user',
        },
        {
            label: '',
            cellComponent: 'table/cell/base',
            filterParam: 'without_driver',
            filterComponent: 'filter/checkbox',
            filterLabel: 'Without Driver Assigned',
            noFilterLabel: true,
            filterable: true,
            hidden: true,
        },
        {
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: 'Order Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '12%',
            actions: [
                {
                    label: this.intl.t('fleet-ops.operations.orders.index.view-order'),
                    icon: 'eye',
                    fn: this.viewOrder,
                    permission: 'fleet-ops view order',
                },
                {
                    label: this.intl.t('fleet-ops.operations.orders.index.dispatch-order'),
                    icon: 'paper-plane',
                    fn: this.dispatchOrder,
                    permission: 'fleet-ops dispatch order',
                    isVisible: (order) => order.canBeDispatched,
                },
                {
                    label: this.intl.t('fleet-ops.operations.orders.index.cancel-order'),
                    icon: 'ban',
                    fn: this.cancelOrder,
                    permission: 'fleet-ops cancel order',
                },
                {
                    separator: true,
                },
                {
                    label: this.intl.t('fleet-ops.operations.orders.index.delete-order'),
                    icon: 'trash',
                    fn: this.deleteOrder,
                    permission: 'fleet-ops delete order',
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
    ];

    /**
     * Creates an instance of OperationsOrdersIndexController.
     * @memberof OperationsOrdersIndexController
     */
    constructor() {
        super(...arguments);
        this.listenForOrderEvents();
        this.getOrderStatusOptions.perform();
        this.getOrderConfigs.perform();
    }

    @task *getOrderStatusOptions() {
        try {
            this.statusOptions = yield this.fetch.get('orders/statuses');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *getOrderConfigs() {
        try {
            this.orderConfigs = yield this.store.query('order-config', { limit: -1 });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Listen for incoming order events to refresh listing.
     *
     * @memberof OperationsOrdersIndexController
     */
    @action async listenForOrderEvents() {
        const findOrder = async (orderId) => {
            const allOrders = this.store.peekAll('order');
            const foundOrder = allOrders.find((order) => order.get('public_id') === orderId);
            if (foundOrder) {
                return foundOrder;
            }

            const tabledOrder = this.table.rows.find((order) => order.get('public_id') === orderId);
            if (tabledOrder) {
                return tabledOrder;
            }

            return this.store.queryRecord('order', { public_id: orderId, single: true });
        };

        const findDriver = async (driverId) => {
            const allDrivers = this.store.peekAll('driver');
            const foundDriver = allDrivers.find((driver) => driver.get('public_id') === driverId);
            if (foundDriver) {
                return foundDriver;
            }

            const tabledOrderWithDriver = this.table.rows.find((order) => order.get('driver_assigned')?.public_id === driverId);
            if (tabledOrderWithDriver) {
                return tabledOrderWithDriver.get('driver_assigned');
            }

            return this.store.queryRecord('driver', { public_id: driverId, single: true });
        };

        // wait for user to be loaded into service
        this.currentUser.on('user.loaded', () => {
            // Get socket instance
            const socket = this.socket.instance();

            // The channel ID to listen on
            const channelId = `company.${this.currentUser.companyId}`;

            // Listed on company channel
            const channel = socket.subscribe(channelId);

            // Disconnect when transitioning
            this.hostRouter.on('routeWillChange', () => {
                channel.close();
            });

            // Listen for channel subscription
            (async () => {
                for await (let output of channel) {
                    const { event, data } = output;
                    debug(`Socket Event : ${event} : ${JSON.stringify(output)}`);

                    if (event === 'order.driver_assigned') {
                        const order = await findOrder(data.id);
                        const driver = await findDriver(data.driver_assigned);

                        if (order && driver) {
                            order.set('driver_assigned', driver);
                        }
                    }
                }
            })();
        });
    }

    /**
     * The search task.
     *
     * @void
     */
    @task({ restartable: true }) *search({ target: { value } }) {
        // if no query don't search
        if (isBlank(value)) {
            this.query = null;
            return;
        }

        // timeout for typing
        yield timeout(250);

        // reset page for results
        if (this.page > 1) {
            this.page = 1;
        }

        // update the query param
        this.query = value;
    }

    /**
     * Reload layout view.
     */
    @action reload() {
        return this.hostRouter.refresh();
    }

    /**
     * Hides all elements on the live map.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action resetView() {
        if (this.leafletMap && this.leafletMap.liveMap) {
            this.leafletMap.liveMap.hideAll();
        }
    }

    /**
     * Toggles the visibility of the search interface.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action toggleSearch() {
        this.isSearchVisible = !this.isSearchVisible;
    }

    /**
     * Set the order list overlay context.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action setOrderListOverlayContext(orderListOverlayContext) {
        this.orderListOverlayContext = orderListOverlayContext;
    }

    /**
     * Toggles the visibility of the orders panel.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action toggleOrdersPanel() {
        if (this.orderListOverlayContext) {
            this.orderListOverlayContext.toggle();
        }
    }

    /**
     * Hides the orders panel.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action hideOrdersPanel() {
        if (this.orderListOverlayContext) {
            this.orderListOverlayContext.close();
        }
    }

    /**
     * Shows the orders panel.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action showOrdersPanel() {
        if (this.orderListOverlayContext) {
            this.orderListOverlayContext.open();
        }
    }

    /**
     * Zooms the map in or out.
     * @param {string} [direction='in'] - The direction to zoom. Either 'in' or 'out'.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action zoomMap(direction = 'in') {
        if (direction === 'in') {
            this.leafletMap?.zoomIn();
        } else {
            this.leafletMap?.zoomOut();
        }
    }

    /**
     * Sets the layout mode and triggers a layout change event.
     * @param {string} mode - The layout mode to set. E.g., 'table'.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action setLayoutMode(mode) {
        this.layout = mode;

        if (mode === 'table') {
            this.isSearchVisible = false;
        }

        this.universe.trigger('fleet-ops.dashboard.layout.changed', mode);
    }

    /**
     * Sets the map references for this component.
     * Extracts the `liveMap` from the `target` object passed in the event and sets it as `this.liveMap`.
     * Also, sets `target` as `this.leafletMap`.
     *
     * @param {Object} event - The event object containing the map references.
     * @param {Object} event.target - The target map object.
     * @param {Object} event.target.liveMap - The live map reference.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action setMapReference({ target }) {
        this.leafletMap = target;
        this.liveMap = target.liveMap;
    }

    @action previewOrderRoute(order) {
        if (this.liveMap) {
            this.liveMap.previewOrderRoute(order);
        }
    }

    @action restoreDefaultLiveMap() {
        if (this.liveMap) {
            this.liveMap.restoreDefaultLiveMap();
        }
    }

    /**
     * Sets the drawer component context api.
     *
     * @param {Object} drawerApi
     * @memberof OperationsOrdersIndexController
     */
    @action setDrawerContext(drawerApi) {
        this.drawer = drawerApi;
    }

    /**
     * Toggles the LiveMap drawer component.
     *
     * @memberof OperationsOrdersIndexController
     */
    @action onPressLiveMapDrawerToggle() {
        if (this.drawer) {
            this.drawer.toggleMinimize({
                onToggle: (drawerApi) => {
                    this.drawerOpen = drawerApi.isMinimized ? 0 : 1;
                },
            });
        }
    }

    /**
     * Handles the resize end event for the `<LiveMapDrawer />` component.
     *
     * @params {Object} event
     * @params {Object.drawerPanelNode|HTMLElement}
     * @memberof OperationsOrdersIndexController
     */
    @action onDrawerResizeEnd({ drawerPanelNode }) {
        const rect = drawerPanelNode.getBoundingClientRect();

        if (rect.height === 0) {
            this.drawerOpen = 0;
        } else if (rect.height > 1) {
            this.drawerOpen = 1;
        }
    }

    @action onDrawerTabChanged(tabName) {
        this.drawerTab = tabName;
    }

    /**
     * Exports all orders.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action exportOrders() {
        const selections = this.table.selectedRows.map((_) => _.id);
        this.crud.export('order', { params: { selections } });
    }

    /**
     * Redirects to the new order creation page.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action createOrder() {
        return this.transitionToRoute('operations.orders.index.new');
    }

    /**
     * Redirects to the view page of a specific order.
     * @param {Object} order - The order to view.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action viewOrder(order) {
        return this.transitionToRoute('operations.orders.index.view', order);
    }

    /**
     * Cancels a specific order after confirmation.
     * @param {Object} order - The order to cancel.
     * @param {Object} [options={}] - Additional options for the modal.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action cancelOrder(order, options = {}) {
        this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.operations.orders.index.cancel-title'),
            body: this.intl.t('fleet-ops.operations.orders.index.cancel-body'),
            order,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.patch('orders/cancel', { order: order.id });
                    order.set('status', 'canceled');
                    this.notifications.success(this.intl.t('fleet-ops.operations.orders.index.cancel-success', { orderId: order.public_id }));
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }

    /**
     * Dispatches a specific order after confirmation.
     * @param {Object} order - The order to dispatch.
     * @param {Object} [options={}] - Additional options for the modal.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action dispatchOrder(order, options = {}) {
        this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.operations.orders.index.dispatch-title'),
            body: this.intl.t('fleet-ops.operations.orders.index.dispatch-body'),
            acceptButtonScheme: 'primary',
            acceptButtonText: 'Dispatch',
            acceptButtonIcon: 'paper-plane',
            order,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.patch('orders/dispatch', { order: order.id });
                    order.set('status', 'dispatched');
                    this.notifications.success(this.intl.t('fleet-ops.operations.orders.index.dispatch-success', { orderId: order.public_id }));
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }

    /**
     * Deletes a specific order.
     * @param {Object} order - The order to delete.
     * @param {Object} [options={}] - Additional options for deletion.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action deleteOrder(order, options = {}) {
        this.crud.delete(order, {
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
            ...options,
        });
    }

    /**
     * Deletes multiple selected orders.
     * @param {Array} [selected=[]] - Orders selected for deletion.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action bulkDeleteOrders(selected = []) {
        selected = selected.length > 0 ? selected : this.table.selectedRows;

        this.crud.bulkDelete(selected, {
            acceptButtonText: 'Delete Orders',
            resolveModelName: (model) => `${model.get('tracking_number.tracking_number')} - ${model.get('public_id')}`,
            onSuccess: async () => {
                this.table.untoggleAllRows();
                await this.hostRouter.refresh();
            },
        });
    }

    /**
     * Cancels multiple selected orders.
     *
     * @param {Array} [selected=[]] - Orders selected for cancellation.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action bulkCancelOrders(selected = []) {
        selected = selected.length > 0 ? selected : this.table.selectedRows;

        if (!isArray(selected) || selected.length === 0) {
            return;
        }

        this.crud.bulkAction('cancel', selected, {
            acceptButtonText: 'Cancel Orders',
            acceptButtonScheme: 'danger',
            acceptButtonIcon: 'ban',
            actionPath: `orders/bulk-cancel`,
            actionMethod: `PATCH`,
            resolveModelName: (model) => `${model.get('tracking_number.tracking_number')} - ${model.get('public_id')}`,
            withSelected: (orders) => {
                orders.forEach((order) => {
                    order.set('status', 'canceled');
                });
            },
            onSuccess: async () => {
                this.table.untoggleAllRows();
                await this.hostRouter.refresh();
            },
        });
    }

    /**
     * Dispatches multiple selected orders.
     *
     * @param {Array} [selected=[]] - Orders selected for dispatch.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action bulkDispatchOrders(selected = []) {
        selected = selected.length > 0 ? selected : this.table.selectedRows;

        if (!isArray(selected) || selected.length === 0) {
            return;
        }

        this.crud.bulkAction('dispatch', selected, {
            acceptButtonText: 'Dispatch Orders',
            acceptButtonScheme: 'magic',
            acceptButtonIcon: 'rocket',
            actionPath: 'orders/bulk-dispatch',
            actionMethod: 'POST',
            resolveModelName: (model) => `${model.get('tracking_number.tracking_number')} - ${model.get('public_id')}`,
            withSelected: (orders) => {
                orders.forEach((order) => {
                    order.set('status', 'dispatched');
                });
            },
            onSuccess: async () => {
                this.table.untoggleAllRows();
                await this.hostRouter.refresh();
            },
        });
    }

    /**
     * Dispatches multiple selected orders.
     *
     * @param {Array} [selected=[]] - Orders selected for dispatch.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action bulkAssignDriver(selected = []) {
        selected = selected.length > 0 ? selected : this.table.selectedRows;
        if (!isArray(selected) || selected.length === 0) {
            return;
        }

        const updateFetchParams = (key, value) => {
            const current = this.modalsManager.getOption('fetchParams') ?? {};
            const next = value === undefined ? Object.fromEntries(Object.entries(current).filter(([k]) => k !== key)) : { ...current, [key]: value };

            this.modalsManager.setOption('fetchParams', next);
        };

        this.crud.bulkAction('assign driver', selected, {
            template: 'modals/bulk-assign-driver',
            acceptButtonText: 'Assign Driver to Orders',
            acceptButtonScheme: 'magic',
            acceptButtonIcon: 'user-plus',
            acceptButtonDisabled: true,
            actionPath: 'orders/bulk-assign-driver',
            actionMethod: 'PATCH',
            driverAssigned: null,
            notifyDriver: true,
            fetchParams: {},
            resolveModelName: (model) => `${model.get('tracking_number.tracking_number')} - ${model.get('public_id')}`,
            selectDriver: (driver) => {
                this.modalsManager.setOptions({
                    driverAssigned: driver,
                    acceptButtonDisabled: driver ? false : true,
                });

                updateFetchParams('driver', driver?.id);
            },
            toggleNotifyDriver: (checked) => {
                this.modalsManager.setOption('notifyDriver', checked);
                updateFetchParams('silent', !checked);
            },
            withSelected: (orders) => {
                const driverAssigned = this.modalsManager.getOption('driverAssigned');
                orders.forEach((order) => {
                    order.set('driver_assigned', driverAssigned);
                });
            },
            onSuccess: async () => {
                this.table.untoggleAllRows();
                await this.hostRouter.refresh();
            },
        });
    }

    /**
     * Triggers when the map container is ready.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action onMapContainerReady() {
        this.fetchActiveOrdersCount();
    }

    /**
     * Fetches the count of active orders.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action fetchActiveOrdersCount() {
        this.fetch.get('fleet-ops/metrics', { discover: ['orders_in_progress'] }).then((response) => {
            this.activeOrdersCount = response.orders_in_progress;
        });
    }

    /**
     * Commits the bulk query to the server for results.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action commitBulkQuery() {
        this.bulk_query = this.bulkSearchValue;
    }

    /**
     * Resets/clear the bulk query search.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action removeBulkQuery() {
        this.bulkSearchValue = '';
        this.bulk_query = null;
    }

    /**
     * Run route optimization wizard.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action optimizeOrderRoutes(selected = []) {
        selected = selected.length > 0 ? selected : this.table.selectedRows;

        return this.hostRouter.transitionTo('console.fleet-ops.operations.routes.index.new', {
            queryParams: {
                selectedOrders: selected.map((_) => _.public_id).join(','),
            },
        });
    }
}
