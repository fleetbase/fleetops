import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class ManagementVehiclesIndexController extends Controller {
    @service vehicleActions;
    @service driverActions;
    @service intl;
    @service appCache;
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
    @tracked statusOptions = [];
    @tracked layout = this.appCache.get('fleetops:vehicles:layout', 'table');
    @tracked actionButtons = [
        {
            component: 'dropdown-button',
            icon: 'display',
            size: 'xs',
            items: [
                {
                    label: 'Table View',
                    icon: 'table-list',
                    onClick: () => {
                        this.layout = 'table';
                        this.appCache.set('fleetops:vehicles:layout', 'table');
                    },
                },
                {
                    label: 'Grid View',
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
            helpText: this.intl.t('fleet-ops.common.reload-data'),
        },
        {
            text: 'New',
            type: 'primary',
            icon: 'plus',
            onClick: this.vehicleActions.transition.create,
        },
        {
            text: 'Import',
            type: 'magic',
            icon: 'upload',
            onClick: this.vehicleActions.import,
        },
        {
            text: 'Export',
            icon: 'long-arrow-up',
            iconClass: 'rotate-icon-45',
            wrapperClass: 'hidden md:flex',
            onClick: this.vehicleActions.export,
        },
    ];
    @tracked bulkActions = [
        {
            label: 'Delete selected...',
            class: 'text-red-500',
            fn: this.vehicleActions.bulkDelete,
        },
    ];
    @tracked columns = [
        {
            label: this.intl.t('fleet-ops.common.name'),
            valuePath: 'display_name',
            photoPath: 'avatar_url',
            width: '200px',
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
            label: this.intl.t('fleet-ops.common.plate-number'),
            valuePath: 'plate_number',
            cellComponent: 'table/cell/base',
            action: this.vehicleActions.transition.view,
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
            filterParam: 'plate_number',
        },
        {
            label: this.intl.t('fleet-ops.common.internal-id'),
            valuePath: 'internal_id',
            cellComponent: 'table/cell/base',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            hidden: true,
            filterComponent: 'filter/string',
            filterParam: 'internal_id',
        },
        {
            label: 'Driver Assigned',
            cellComponent: 'table/cell/anchor',
            permission: 'fleet-ops view driver',
            action: async (vehicle) => {
                const driver = await vehicle.loadDriver();

                return this.driverActions.panel.view(driver);
            },
            valuePath: 'driver_name',
            width: '120px',
            resizable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select driver to filter by',
            filterParam: 'driver',
            model: 'driver',
        },
        {
            label: this.intl.t('fleet-ops.common.id'),
            valuePath: 'public_id',
            cellComponent: 'click-to-copy',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.make'),
            valuePath: 'make',
            cellComponent: 'table/cell/base',
            width: '80px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterParam: 'make',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.model'),
            valuePath: 'model',
            cellComponent: 'table/cell/base',
            width: '80px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterParam: 'model',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.year'),
            valuePath: 'year',
            cellComponent: 'table/cell/base',
            width: '80px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.vendor'),
            cellComponent: 'table/cell/anchor',
            permission: 'fleet-ops view vendor',
            action: async ({ vendor_uuid }) => {
                const vendor = await this.store.findRecord('vendor', vendor_uuid);

                this.vendors.viewVendor(vendor);
            },
            valuePath: 'vendor_name',
            width: '150px',
            hidden: true,
            resizable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select vendor to filter by',
            filterParam: 'vendor',
            model: 'vendor',
        },
        {
            label: this.intl.t('fleet-ops.common.status'),
            valuePath: 'status',
            cellComponent: 'table/cell/status',
            width: '10%',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/multi-option',
            filterFetchOptions: 'vehicles/statuses',
        },
        {
            label: this.intl.t('fleet-ops.common.created-at'),
            valuePath: 'createdAt',
            sortParam: 'created_at',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'created_at',
            filterLabel: 'Created Between',
            filterComponent: 'filter/date',
        },
        {
            label: this.intl.t('fleet-ops.common.updated-at'),
            valuePath: 'updatedAt',
            sortParam: 'updated_at',
            width: '12%',
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
            ddMenuLabel: 'Vehicle Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '90px',
            actions: [
                {
                    label: this.intl.t('fleet-ops.management.vehicles.index.view-vehicle'),
                    fn: this.vehicleActions.transition.view,
                    permission: 'fleet-ops view vehicle',
                },
                {
                    label: this.intl.t('fleet-ops.management.vehicles.index.edit-vehicle'),
                    fn: this.vehicleActions.transition.edit,
                    permission: 'fleet-ops update vehicle',
                },
                {
                    label: this.intl.t('fleet-ops.management.vehicles.index.locate-action-title'),
                    fn: this.vehicleActions.locate,
                    permission: 'fleet-ops view vehicle',
                },
                {
                    separator: true,
                },
                {
                    label: this.intl.t('fleet-ops.management.vehicles.index.delete-vehicle'),
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
