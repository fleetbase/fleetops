import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { equal } from '@ember/object/computed';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class OperationsOrdersIndexController extends Controller {
    @service orderActions;
    @service orderSocketEvents;
    @service leafletMapManager;
    @service orderListOverlay;
    @service fetch;
    @service intl;

    /** query params */
    @tracked queryParams = [
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
    @tracked focusedDriver;
    @tracked focusedVehicle;
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked internal_id;
    @tracked tracking;
    @tracked facilitator;
    @tracked customer;
    @tracked driver;
    @tracked vehicle;
    @tracked payload;
    @tracked pickup;
    @tracked dropoff;
    @tracked updated_by;
    @tracked created_by;
    @tracked created_at;
    @tracked updated_at;
    @tracked scheduled_at;
    @tracked without_driver;
    @tracked status;
    @tracked type;
    @tracked isSearchVisible = false;
    @tracked isOrdersPanelVisible = false;
    @tracked activeOrdersCount = 0;
    @tracked orderListOverlayContext;
    @tracked leafletMap;
    @tracked drawer;
    @tracked layout = 'map';
    @tracked drawerOpen = 0;
    @tracked drawerTab;
    @tracked statusOptions = [];
    @tracked orderConfigs = [];
    @tracked bulkSearchValue = '';
    @tracked bulk_query = '';

    /** action buttons */
    @tracked actionButtons = [
        {
            icon: 'refresh',
            onClick: this.orderActions.refresh,
            helpText: this.intl.t('fleet-ops.common.reload-data'),
        },
        {
            text: 'New',
            type: 'primary',
            icon: 'plus',
            onClick: this.orderActions.transition.create,
        },
        {
            text: 'Export',
            icon: 'long-arrow-up',
            iconClass: 'rotate-icon-45',
            wrapperClass: 'hidden md:flex',
            onClick: this.orderActions.export,
        },
    ];

    /** bulk actions */
    @tracked bulkActions = [
        {
            label: 'Cancel Orders',
            icon: 'ban',
            fn: this.orderActions.bulkCancel,
        },
        {
            label: 'Delete Orders',
            icon: 'trash',
            class: 'text-red-500',
            fn: this.orderActions.bulkDelete,
        },
        { separator: true },
        {
            label: 'Dispatch Orders',
            icon: 'rocket',
            fn: this.orderActions.bulkDispatch,
        },
        {
            label: 'Assign Driver',
            icon: 'user-plus',
            fn: this.orderActions.bulkAssignDriver,
        },
    ];

    /** columns */
    @tracked columns = [
        {
            label: this.intl.t('fleet-ops.common.id'),
            valuePath: 'public_id',
            width: '140px',
            cellComponent: 'table/cell/link-to',
            route: 'operations.orders.index.details',
            onLinkClick: this.orderActions.transition.view,
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
            filterComponent: 'filter/model',
            model: 'order-config',
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
            fetchUri: 'orders/statuses',
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
                    fn: this.orderActions.transition.view,
                    permission: 'fleet-ops view order',
                },
                {
                    label: this.intl.t('fleet-ops.operations.orders.index.dispatch-order'),
                    icon: 'paper-plane',
                    fn: this.orderActions.dispatch,
                    permission: 'fleet-ops dispatch order',
                    isVisible: (order) => order.canBeDispatched,
                },
                {
                    label: this.intl.t('fleet-ops.operations.orders.index.cancel-order'),
                    icon: 'ban',
                    fn: this.orderActions.cancel,
                    permission: 'fleet-ops cancel order',
                },
                {
                    separator: true,
                },
                {
                    label: this.intl.t('fleet-ops.operations.orders.index.delete-order'),
                    icon: 'trash',
                    fn: this.orderActions.delete,
                    permission: 'fleet-ops delete order',
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
    ];

    constructor() {
        super(...arguments);
        this.orderSocketEvents.startCompany();
    }

    @action changeLayout(mode) {
        this.layout = mode;

        if (mode === 'table') {
            this.isSearchVisible = false;
        }
    }

    @action toggleSearch() {
        this.isSearchVisible = !this.isSearchVisible;
    }

    @action toggleOrdersPanel() {
        if (this.orderListOverlayContext) {
            this.orderListOverlayContext.toggle();
        }
    }

    @action hideOrdersPanel() {
        if (this.orderListOverlayContext) {
            this.orderListOverlayContext.close();
        }
    }

    @action showOrdersPanel() {
        if (this.orderListOverlayContext) {
            this.orderListOverlayContext.open();
        }
    }

    @action previewOrderRoute(order) {
        this.leafletMapManager.livemap('previewOrderRoute', order);
    }

    @action setDrawerContext(drawerApi) {
        this.drawer = drawerApi;
    }

    @action onPressLiveMapDrawerToggle() {
        if (this.drawer) {
            this.drawer.toggleMinimize({
                onToggle: (drawerApi) => {
                    this.drawerOpen = drawerApi.isMinimized ? 0 : 1;
                },
            });
        }
    }

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
}
