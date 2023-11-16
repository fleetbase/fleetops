import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { equal } from '@ember/object/computed';
import { isArray } from '@ember/array';
import { isBlank } from '@ember/utils';
import { timeout } from 'ember-concurrency';
import { task } from 'ember-concurrency-decorators';

export default class OperationsOrdersIndexController extends Controller {
    /**
     * Inject the `currentUser` service
     *
     * @var {Service}
     */
    @service currentUser;

    /**
     * Inject the `fetch` service
     *
     * @var {Service}
     */
    @service fetch;

    /**
     * Inject the `filters` service
     *
     * @var {Service}
     */
    @service filters;

    /**
     * Inject the `hostRouter` service
     *
     * @var {Service}
     */
    @service hostRouter;

    /**
     * Inject the `notifications` service
     *
     * @var {Service}
     */
    @service notifications;

    /**
     * Inject the `modalsManager` service
     *
     * @var {Service}
     */
    @service modalsManager;

    /**
     * Inject the `crud` service
     *
     * @var {Service}
     */
    @service crud;

    /**
     * Inject the `universe` service
     *
     * @var {Service}
     */
    @service universe;

    /**
     * Inject the `socket` service
     *
     * @var {Service}
     */
    @service socket;

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
        'pickup',
        'dropoff',
        'created_by',
        'updated_by',
        'status',
        'type',
        'layout',
        'drawerOpen',
        'drawerTab',
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
            label: 'ID',
            valuePath: 'public_id',
            width: '150px',
            cellComponent: 'table/cell/link-to',
            route: 'operations.orders.index.view',
            onLinkClick: this.viewOrder,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Internal ID',
            valuePath: 'internal_id',
            width: '125px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Payload',
            valuePath: 'payload.public_id',
            resizable: true,
            hidden: true,
            width: '125px',
            filterable: true,
            filterLabel: 'Payload ID',
            filterParam: 'payload',
            filterComponent: 'filter/string',
        },
        {
            label: 'Customer',
            valuePath: 'customer.name',
            cellComponent: 'table/cell/base',
            width: '125px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select order customer',
            filterParam: 'customer',
            model: 'customer',
        },
        {
            label: 'Facilitator',
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
            label: 'Pickup',
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
            label: 'Dropoff',
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
            label: 'Scheduled At',
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
            label: '# Items',
            cellComponent: 'table/cell/base',
            valuePath: 'item_count',
            resizable: true,
            hidden: true,
            width: '50px',
        },
        {
            label: 'Transaction Total',
            cellComponent: 'table/cell/base',
            valuePath: 'transaction_amount',
            width: '50px',
            resizable: true,
            hidden: true,
            sortable: true,
        },
        {
            label: 'Tracking Number',
            cellComponent: 'table/cell/base',
            valuePath: 'tracking_number.tracking_number',
            width: '170px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Driver Assigned',
            cellComponent: 'table/cell/driver-name',
            valuePath: 'driver_assigned',
            modelPath: 'driver_assigned',
            width: '170px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select driver for order',
            filterParam: 'driver',
            model: 'driver',
        },
        {
            label: 'Type',
            valuePath: 'type',
            width: '100px',
            resizable: true,
            hidden: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/select',
            filterOptions: this.orderTypes,
            filterOptionLabel: 'name',
            filterOptionValue: 'key',
            filterComponentPlaceholder: 'Filter by order type',
        },
        {
            label: 'Status',
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
            label: 'Created At',
            valuePath: 'createdAt',
            sortParam: 'created_at',
            filterParam: 'created_at',
            width: '140px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: 'Updated At',
            valuePath: 'updatedAt',
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
            label: 'Created By',
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
            label: 'Updated By',
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
                    label: 'View Order',
                    icon: 'eye',
                    fn: this.viewOrder,
                },
                {
                    label: 'Cancel Order',
                    icon: 'ban',
                    fn: this.cancelOrder,
                },
                {
                    separator: true,
                },
                {
                    label: 'Delete Order',
                    icon: 'trash',
                    fn: this.deleteOrder,
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
    }

    /**
     * Listen for incoming order events to refresh listing.
     *
     * @memberof OperationsOrdersIndexController
     */
    @action async listenForOrderEvents() {
        // wait for user to be loaded into service
        this.currentUser.on('user.loaded', () => {
            // Get socket instance
            const socket = this.socket.instance();

            // The channel ID to listen on
            const channelId = `company.${this.currentUser.companyId}`;

            // Listed on company channel
            const channel = socket.subscribe(channelId);

            // Events which should trigger refresh
            const listening = ['order.ready', 'order.driver_assigned'];

            // Listen for channel subscription
            (async () => {
                for await (let output of channel) {
                    const { event } = output;

                    // if an order event refresh orders
                    if (typeof event === 'string' && listening.includes(event)) {
                        this.hostRouter.refresh();
                    }
                }
            })();

            // disconnect when transitioning
            this.hostRouter.on('routeWillChange', () => {
                channel.close();
            });
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
     * Toggles the visibility of the orders panel.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action toggleOrdersPanel() {
        this.isOrdersPanelVisible = !this.isOrdersPanelVisible;
    }

    /**
     * Hides the orders panel.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action hideOrdersPanel() {
        this.isOrdersPanelVisible = false;
    }

    /**
     * Shows the orders panel.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action showOrdersPanel() {
        this.isOrdersPanelVisible = true;
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

        this.universe.trigger('dashboard.layout.changed', mode);
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
    @action setMapReference({ target, target: { liveMap } }) {
        this.leafletMap = target;
        this.liveMap = liveMap;
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
        this.crud.export('order');
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
            title: `Are you sure you wish to cancel this order?`,
            body: `Once this order is canceled, the order record will still be visible but activity cannot be added to this order.`,
            order,
            confirm: (modal) => {
                modal.startLoading();

                return this.fetch.patch(`orders/cancel`, { order: order.id }).then(() => {
                    order.set('status', 'canceled');
                    this.notifications.success(`Order ${order.public_id} has been canceled.`);
                });
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
            title: `Are you sure you want to dispatch this order?`,
            body: `Once this order is dispatched the assigned driver will be notified.`,
            acceptButtonScheme: 'primary',
            acceptButtonText: 'Dispatch',
            acceptButtonIcon: 'paper-plane',
            order,
            confirm: (modal) => {
                modal.startLoading();

                return this.fetch
                    .patch(`orders/dispatch`, { order: order.id })
                    .then(() => {
                        order.set('status', 'dispatched');
                        this.notifications.success(`Order ${order.public_id} has been dispatched.`);
                    })
                    .catch((error) => {
                        modal.stopLoading();
                        this.notifications.serverError(error);
                    });
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
            modelNamePath: `public_id`,
            acceptButtonText: 'Delete Orders',
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
        });
    }

    /**
     * Cancels multiple selected orders.
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
            modelNamePath: `public_id`,
            actionPath: `orders/bulk-cancel`,
            actionMethod: `PATCH`,
            onConfirm: (canceledOrders) => {
                canceledOrders.forEach((order) => {
                    order.set('status', 'canceled');
                });
            },
            onSuccess: () => {
                return this.hostRouter.refresh();
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
        this.fetch.get('fleet-ops/metrics/all', { discover: ['orders_in_progress'] }).then((response) => {
            this.activeOrdersCount = response.orders_in_progress;
        });
    }
}
