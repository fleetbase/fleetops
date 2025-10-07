import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { equal } from '@ember/object/computed';

export default class ManagementDriversIndexController extends Controller {
    @service driverActions;
    @service fleetActions;
    @service vendorActions;
    @service vehicleActions;
    @service notifications;
    @service intl;
    @equal('layout', 'grid') isGridLayout;
    @equal('layout', 'table') isTableLayout;
    @tracked queryParams = [
        'page',
        'limit',
        'sort',
        'query',
        'name',
        'drivers_license_number',
        'vehicle',
        'fleet',
        'vendor',
        'phone',
        'country',
        'public_id',
        'internal_id',
        'created_at',
        'updated_at',
        'status',
    ];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked internal_id;
    @tracked name;
    @tracked vehicle;
    @tracked fleet;
    @tracked drivers_license_number;
    @tracked phone;
    @tracked status;
    @tracked created_at;
    @tracked updated_at;
    @tracked layout = 'table';
    @tracked table;
    @tracked actionButtons = [
        {
            icon: 'refresh',
            onClick: this.driverActions.refresh,
            helpText: this.intl.t('fleet-ops.common.reload-data'),
        },
        {
            text: 'New',
            type: 'primary',
            icon: 'plus',
            onClick: this.driverActions.transition.create,
        },
        {
            text: 'Import',
            type: 'magic',
            icon: 'upload',
            onClick: this.driverActions.import,
        },
        {
            text: 'Export',
            icon: 'long-arrow-up',
            iconClass: 'rotate-icon-45',
            wrapperClass: 'hidden md:flex',
            onClick: this.driverActions.export,
        },
    ];
    @tracked bulkActions = [
        {
            label: 'Delete selected...',
            class: 'text-red-500',
            fn: this.driverActions.bulkDelete,
        },
    ];
    @tracked columns = [
        {
            label: this.intl.t('fleet-ops.common.name'),
            valuePath: 'name',
            width: '200px',
            cellComponent: 'table/cell/driver-name',
            permission: 'fleet-ops view driver',
            action: this.driverActions.transition.view,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.id'),
            valuePath: 'public_id',
            width: '130px',
            cellComponent: 'click-to-copy',
            resizable: true,
            sortable: true,
            filterable: true,
            hidden: false,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.internal-id'),
            valuePath: 'internal_id',
            cellComponent: 'click-to-copy',
            width: '130px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.vendor'),
            cellComponent: 'table/cell/anchor',
            permission: 'fleet-ops view vendor',
            onClick: async (driver) => {
                try {
                    const vendor = await driver.loadVendor();
                    if (vendor) this.vendorActions.panel.view(vendor);
                } catch (err) {
                    this.notifications.serverError(err);
                }
            },
            valuePath: 'vendor.name',
            modelNamePath: 'name',
            width: '180px',
            resizable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select vendor to filter by',
            filterParam: 'vendor',
            model: 'vendor',
        },
        {
            label: this.intl.t('fleet-ops.common.vehicle'),
            cellComponent: 'table/cell/anchor',
            permission: 'fleet-ops view vehicle',
            onClick: async (driver) => {
                try {
                    const vehicle = await driver.loadVehicle();
                    if (vehicle) this.vehicleActions.panel.view(vehicle);
                } catch (err) {
                    this.notifications.serverError(err);
                }
            },
            valuePath: 'vehicle.display_name',
            modelNamePath: 'display_name',
            resizable: true,
            width: '180px',
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select vehicle to filter by',
            filterParam: 'vehicle',
            model: 'vehicle',
        },
        {
            label: this.intl.t('fleet-ops.common.fleet'),
            cellComponent: 'table/cell/link-list',
            cellComponentLabelPath: 'name',
            action: (fleet) => {
                this.fleetActions.panel.view(fleet);
            },
            valuePath: 'fleets',
            width: '180px',
            resizable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select fleet to filter by',
            filterParam: 'fleet',
            model: 'fleet',
        },
        {
            label: this.intl.t('fleet-ops.common.license'),
            valuePath: 'drivers_license_number',
            cellComponent: 'table/cell/base',
            width: '150px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.phone'),
            valuePath: 'phone',
            cellComponent: 'table/cell/base',
            width: '150px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'phone',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.country'),
            valuePath: 'country',
            cellComponent: 'table/cell/country',
            cellClassNames: 'uppercase',
            width: '120px',
            resizable: true,
            hidden: true,
            sortable: true,
            filterable: true,
            filterParam: 'country',
            filterComponent: 'filter/multi-option',
            filterFetchOptions: 'lookup/countries',
            filterOptionLabel: 'name',
            filterOptionValue: 'cca2',
            multiOptionSearchEnabled: true,
            multiOptionSearchPlaceholder: 'Search countries...',
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
            filterFetchOptions: 'drivers/statuses',
        },
        {
            label: this.intl.t('fleet-ops.common.created-at'),
            valuePath: 'createdAt',
            sortParam: 'created_at',
            filterParam: 'created_at',
            width: '130px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: this.intl.t('fleet-ops.common.updated-at'),
            valuePath: 'updatedAt',
            sortParam: 'updated_at',
            filterParam: 'updated_at',
            width: '130px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: 'Driver Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '10%',
            actions: [
                {
                    label: this.intl.t('fleet-ops.management.drivers.index.view-details'),
                    fn: this.driverActions.transition.view,
                    permission: 'fleet-ops view driver',
                },
                {
                    label: this.intl.t('fleet-ops.management.drivers.index.edit-details'),
                    fn: this.driverActions.transition.edit,
                    permission: 'fleet-ops update driver',
                },
                {
                    separator: true,
                },
                {
                    label: this.intl.t('fleet-ops.management.drivers.index.assign-order-driver'),
                    fn: this.driverActions.assignOrder,
                    permission: 'fleet-ops assign-order-for driver',
                },
                {
                    label: this.intl.t('fleet-ops.management.drivers.index.assign-vehicle-driver'),
                    fn: this.driverActions.assignVehicle,
                    permission: 'fleet-ops assign-vehicle-for driver',
                },
                {
                    label: this.intl.t('fleet-ops.management.drivers.index.locate-driver-map'),
                    fn: this.driverActions.locate,
                    permission: 'fleet-ops view driver',
                },
                {
                    separator: true,
                },
                {
                    label: this.intl.t('fleet-ops.management.drivers.index.delete-driver'),
                    fn: this.driverActions.delete,
                    permission: 'fleet-ops delete driver',
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
    ];

    @action changeLayout(layout) {
        this.layout = layout;
    }
}
