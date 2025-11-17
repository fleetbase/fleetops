import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class ManagementVehiclesIndexController extends Controller {
    @service vehicleActions;
    @service driverActions;
    @service tableContext;
    @service intl;
    @service appCache;

    /** query params */
    @tracked queryParams = [
        'page',
        'limit',
        'sort',
        'query',
        'public_id',
        'status',
        'created_at',
        'updated_at',
        'created_by',
        'updated_by',
        'name',
        'plate_number',
        'year',
        'vehicle_make',
        'vehicle_model',
        'display_name',
    ];
    @tracked query = null;
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked status;
    @tracked name;
    @tracked plate_number;
    @tracked vehicle_make;
    @tracked vehicle_model;
    @tracked year;
    @tracked country;
    @tracked fleet;
    @tracked vendor;
    @tracked driver;
    @tracked display_name;
    @tracked table;
    @tracked layout = this.appCache.get('fleetops:vehicles:layout', 'table');

    /** action buttons */
    /* eslint-disable ember/no-side-effects */
    get actionButtons() {
        return [
            {
                component: 'dropdown-button',
                icon: 'display',
                size: 'xs',
                items: [
                    {
                        label: this.intl.t('common.table-view'),
                        icon: 'table-list',
                        onClick: () => {
                            this.layout = 'table';
                            this.appCache.set('fleetops:vehicles:layout', 'table');
                        },
                    },
                    {
                        label: this.intl.t('common.grid-view'),
                        icon: 'grip',
                        onClick: () => {
                            this.layout = 'grid';
                            this.appCache.set('fleetops:vehicles:layout', 'grid');
                        },
                    },
                ],
                renderInPlace: true,
                helpText: 'Change the layout',
            },
            {
                icon: 'refresh',
                onClick: this.vehicleActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
            {
                text: this.intl.t('common.new'),
                type: 'primary',
                icon: 'plus',
                onClick: this.vehicleActions.transition.create,
            },
            {
                text: this.intl.t('common.import'),
                type: 'magic',
                icon: 'upload',
                onClick: this.vehicleActions.import,
            },
            {
                text: this.intl.t('common.export'),
                icon: 'long-arrow-up',
                iconClass: 'rotate-icon-45',
                wrapperClass: 'hidden md:flex',
                onClick: this.vehicleActions.export,
            },
        ];
    }

    /** bulk actions */
    get bulkActions() {
        const selected = this.tableContext.getSelectedRows();

        return [
            {
                label: this.intl.t('common.delete-selected-count', { count: selected.length }),
                class: 'text-red-500',
                fn: this.vehicleActions.bulkDelete,
            },
        ];
    }

    /** columns */
    get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('column.name'),
                valuePath: 'display_name',
                photoPath: 'avatar_url',
                cellComponent: 'table/cell/vehicle-name',
                permission: 'fleet-ops view vehicle',
                action: this.vehicleActions.transition.view,
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
                filterParam: 'display_name',
                showOnlineIndicator: true,
            },
            {
                label: this.intl.t('column.plate-number'),
                valuePath: 'plate_number',
                cellComponent: 'table/cell/base',
                action: this.vehicleActions.transition.view,
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
                filterParam: 'plate_number',
            },
            {
                label: this.intl.t('column.internal-id'),
                valuePath: 'internal_id',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
                filterable: true,
                hidden: true,
                filterComponent: 'filter/string',
                filterParam: 'internal_id',
            },
            {
                label: this.intl.t('column.driver-assigned'),
                cellComponent: 'table/cell/anchor',
                permission: 'fleet-ops view driver',
                action: async (vehicle) => {
                    const driver = await vehicle.loadDriver();

                    return this.driverActions.panel.view(driver);
                },
                valuePath: 'driver_name',
                resizable: true,
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: 'Select driver to filter by',
                filterParam: 'driver',
                model: 'driver',
            },
            {
                label: this.intl.t('column.id'),
                valuePath: 'public_id',
                cellComponent: 'click-to-copy',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.make'),
                valuePath: 'make',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: true,
                filterParam: 'make',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.model'),
                valuePath: 'model',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: true,
                filterParam: 'model',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.year'),
                valuePath: 'year',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.vendor'),
                cellComponent: 'table/cell/anchor',
                permission: 'fleet-ops view vendor',
                action: async ({ vendor_uuid }) => {
                    const vendor = await this.store.findRecord('vendor', vendor_uuid);

                    this.vendorActions.viewVendor(vendor);
                },
                valuePath: 'vendor_name',
                hidden: true,
                resizable: true,
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: this.intl.t('select-resource-filter-by', { resource: this.intl.t('resource.vendor') }),
                filterParam: 'vendor',
                model: 'vendor',
            },
            {
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/multi-option',
                filterFetchOptions: 'vehicles/statuses',
            },
            {
                label: this.intl.t('column.created-at'),
                valuePath: 'createdAt',
                sortParam: 'created_at',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'created_at',
                filterLabel: 'Created Between',
                filterComponent: 'filter/date',
            },
            {
                label: this.intl.t('column.updated-at'),
                valuePath: 'updatedAt',
                sortParam: 'updated_at',
                resizable: true,
                sortable: true,
                hidden: true,
                filterParam: 'updated_at',
                filterLabel: 'Last Updated Between',
                filterable: true,
                filterComponent: 'filter/date',
            },
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.vehicle') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.vehicle') }),
                        fn: this.vehicleActions.transition.view,
                        permission: 'fleet-ops view vehicle',
                    },
                    {
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.vehicle') }),
                        fn: this.vehicleActions.transition.edit,
                        permission: 'fleet-ops update vehicle',
                    },
                    {
                        label: this.intl.t('vehicle.actions.locate-vehicle'),
                        fn: this.vehicleActions.locate,
                        permission: 'fleet-ops view vehicle',
                    },
                    {
                        separator: true,
                    },
                    {
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.vehicle') }),
                        fn: this.vehicleActions.delete,
                        permission: 'fleet-ops delete vehicle',
                    },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
    }
}
