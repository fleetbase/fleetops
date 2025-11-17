import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class OperationsOrdersIndexController extends Controller {
    @service orderActions;
    @service orderSocketEvents;
    @service leafletMapManager;
    @service mapDrawer;
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
    @tracked orderConfig;
    @tracked bulkSearchValue = '';
    @tracked bulk_query = '';
    @tracked layout = 'map';

    /** action buttons */
    get actionButtons() {
        return [
            {
                icon: 'refresh',
                onClick: this.orderActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
            {
                text: this.intl.t('common.new'),
                type: 'primary',
                icon: 'plus',
                onClick: this.orderActions.transition.create,
            },
            {
                text: this.intl.t('common.export'),
                icon: 'long-arrow-up',
                iconClass: 'rotate-icon-45',
                wrapperClass: 'hidden md:flex',
                onClick: this.orderActions.export,
            },
        ];
    }

    /** bulk actions */
    get bulkActions() {
        return [
            {
                label: this.intl.t('common.cancel-resource', { resource: this.intl.t('resource.orders') }),
                icon: 'ban',
                fn: this.orderActions.bulkCancel,
            },
            {
                label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.orders') }),
                icon: 'trash',
                class: 'text-red-500',
                fn: this.orderActions.bulkDelete,
            },
            { separator: true },
            {
                label: this.intl.t('common.dispatch-orders'),
                icon: 'rocket',
                fn: this.orderActions.bulkDispatch,
            },
            {
                label: this.intl.t('common.assign-driver'),
                icon: 'user-plus',
                fn: this.orderActions.bulkAssignDriver,
            },
        ];
    }

    /** columns */
    get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('column.id'),
                valuePath: 'public_id',
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
                label: this.intl.t('column.internal-id'),
                valuePath: 'internal_id',
                cellComponent: 'click-to-copy',
                hidden: true,
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.payload'),
                valuePath: 'payload.public_id',
                cellComponent: 'click-to-copy',
                resizable: true,
                hidden: true,
                filterable: true,
                filterLabel: 'Payload ID',
                filterParam: 'payload',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.driver-assigned'),
                cellComponent: 'cell/driver-name',
                valuePath: 'driver_assigned',
                modelPath: 'driver_assigned',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: 'Select driver for order',
                filterParam: 'driver',
                model: 'driver',
            },
            {
                label: this.intl.t('column.pickup'),
                valuePath: 'pickupName',
                cellComponent: 'table/cell/base',
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
                label: this.intl.t('column.dropoff'),
                valuePath: 'dropoffName',
                cellComponent: 'table/cell/base',
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
                label: this.intl.t('column.customer'),
                valuePath: 'customer.name',
                cellComponent: 'table/cell/base',
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
                label: this.intl.t('column.vehicle-assigned'),
                cellComponent: 'cell/vehicle-name',
                valuePath: 'vehicle_assigned.display_name',
                modelPath: 'vehicle_assigned',
                showOnlineIndicator: true,
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
                label: this.intl.t('column.facilitator'),
                valuePath: 'facilitator.name',
                cellComponent: 'table/cell/base',
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
                label: this.intl.t('column.scheduled-at'),
                valuePath: 'scheduledAt',
                sortParam: 'scheduled_at',
                filterParam: 'scheduled_at',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/date',
            },
            {
                label: this.intl.t('column.items'),
                cellComponent: 'table/cell/base',
                valuePath: 'item_count',
                resizable: true,
                hidden: true,
            },
            {
                label: this.intl.t('column.transaction'),
                cellComponent: 'table/cell/base',
                valuePath: 'transaction_amount',
                resizable: true,
                hidden: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.tracking'),
                valuePath: 'tracking_number.tracking_number',
                cellComponent: 'click-to-copy',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.type'),
                valuePath: 'type',
                resizable: true,
                hidden: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/model',
                model: 'order-config',
                filterComponentPlaceholder: 'Filter by order config',
            },
            {
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/multi-option',
                fetchUri: 'orders/statuses',
            },
            {
                label: this.intl.t('column.created-at'),
                valuePath: 'createdAt',
                sortParam: 'created_at',
                filterParam: 'created_at',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/date',
            },
            {
                label: this.intl.t('column.updated-at'),
                valuePath: 'updatedAt',
                sortParam: 'updated_at',
                filterParam: 'updated_at',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: true,
                filterComponent: 'filter/date',
            },
            {
                label: this.intl.t('column.created-by'),
                valuePath: 'created_by_name',
                resizable: true,
                hidden: true,
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: 'Select user',
                filterParam: 'created_by',
                model: 'user',
            },
            {
                label: this.intl.t('column.updated-by'),
                valuePath: 'updated_by_name',
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
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.order') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.order') }),
                        icon: 'eye',
                        fn: this.orderActions.transition.view,
                        permission: 'fleet-ops view order',
                    },
                    {
                        label: this.intl.t('common.dispatch-order'),
                        icon: 'paper-plane',
                        fn: this.orderActions.dispatch,
                        permission: 'fleet-ops dispatch order',
                        isVisible: (order) => order.canBeDispatched,
                    },
                    {
                        label: this.intl.t('common.cancel-resource', { resource: this.intl.t('resource.order') }),
                        icon: 'ban',
                        fn: this.orderActions.cancel,
                        permission: 'fleet-ops cancel order',
                    },
                    {
                        separator: true,
                    },
                    {
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.order') }),
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
    }

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
}
