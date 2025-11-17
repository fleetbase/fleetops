import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class ManagementDriversIndexController extends Controller {
    @service driverActions;
    @service fleetActions;
    @service vendorActions;
    @service vehicleActions;
    @service notifications;
    @service tableContext;
    @service intl;

    /** query params */
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

    /** action buttons */
    get actionButtons() {
        return [
            {
                icon: 'refresh',
                onClick: this.driverActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
            {
                text: this.intl.t('common.new'),
                type: 'primary',
                icon: 'plus',
                onClick: this.driverActions.transition.create,
            },
            {
                text: this.intl.t('common.import'),
                type: 'magic',
                icon: 'upload',
                onClick: this.driverActions.import,
            },
            {
                text: this.intl.t('common.export'),
                icon: 'long-arrow-up',
                iconClass: 'rotate-icon-45',
                wrapperClass: 'hidden md:flex',
                onClick: this.driverActions.export,
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
                fn: this.driverActions.bulkDelete,
            },
        ];
    }

    /** columns */
    get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('column.name'),
                valuePath: 'name',
                cellComponent: 'table/cell/driver-name',
                permission: 'fleet-ops view driver',
                action: this.driverActions.transition.view,
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.id'),
                valuePath: 'public_id',
                cellComponent: 'click-to-copy',
                resizable: true,
                sortable: true,
                filterable: true,
                hidden: false,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.internal-id'),
                valuePath: 'internal_id',
                cellComponent: 'click-to-copy',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.vendor'),
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
                resizable: true,
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: 'Select vendor to filter by',
                filterParam: 'vendor',
                model: 'vendor',
            },
            {
                label: this.intl.t('column.vehicle'),
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
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: 'Select vehicle to filter by',
                filterParam: 'vehicle',
                model: 'vehicle',
            },
            {
                label: this.intl.t('column.fleet'),
                cellComponent: 'table/cell/link-list',
                cellComponentLabelPath: 'name',
                action: this.fleetActions.panel.view,
                valuePath: 'fleets',
                resizable: true,
                hidden: true,
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: 'Select fleet to filter by',
                filterParam: 'fleet',
                model: 'fleet',
            },
            {
                label: this.intl.t('column.license'),
                valuePath: 'drivers_license_number',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.phone'),
                valuePath: 'phone',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'phone',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.country'),
                valuePath: 'country',
                cellComponent: 'table/cell/country',
                cellClassNames: 'uppercase',
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
                multiOptionSearchPlaceholder: this.intl.t('common.search-countries'),
            },
            {
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/multi-option',
                filterFetchOptions: 'drivers/statuses',
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
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.driver') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.driver') }),
                        fn: this.driverActions.transition.view,
                        permission: 'fleet-ops view driver',
                    },
                    {
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.driver') }),
                        fn: this.driverActions.transition.edit,
                        permission: 'fleet-ops update driver',
                    },
                    {
                        separator: true,
                    },
                    {
                        label: this.intl.t('driver.actions.assign-order'),
                        fn: this.driverActions.assignOrder,
                        permission: 'fleet-ops assign-order-for driver',
                    },
                    {
                        label: this.intl.t('driver.actions.assign-vehicle'),
                        fn: this.driverActions.assignVehicle,
                        permission: 'fleet-ops assign-vehicle-for driver',
                    },
                    {
                        label: this.intl.t('driver.actions.locate-driver'),
                        fn: this.driverActions.locate,
                        permission: 'fleet-ops view driver',
                    },
                    {
                        separator: true,
                    },
                    {
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.driver') }),
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
    }

    @action changeLayout(layout) {
        this.layout = layout;
    }
}
