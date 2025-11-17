import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class ManagementFuelReportsIndexController extends Controller {
    @service fuelReportActions;
    @service tableContext;
    @service intl;

    /** query params */
    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'public_id', 'internal_id', 'vehicle', 'driver', 'created_by', 'updated_by', 'status', 'country', 'volume', 'odometer'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked internal_id;
    @tracked driver;
    @tracked vehicle;
    @tracked reporter;
    @tracked volume;
    @tracked odometer;
    @tracked status;
    @tracked table;

    /** action buttons */
    get actionButtons() {
        return [
            {
                icon: 'refresh',
                onClick: this.fuelReportActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
            {
                text: this.intl.t('common.new'),
                type: 'primary',
                icon: 'plus',
                onClick: this.fuelReportActions.transition.create,
            },
            {
                text: this.intl.t('common.import'),
                type: 'magic',
                icon: 'upload',
                onClick: this.fuelReportActions.import,
            },
            {
                text: this.intl.t('common.export'),
                icon: 'long-arrow-up',
                iconClass: 'rotate-icon-45',
                wrapperClass: 'hidden md:flex',
                onClick: this.fuelReportActions.export,
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
                fn: this.fuelReportActions.bulkDelete,
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
                cellComponent: 'table/cell/anchor',
                action: this.fuelReportActions.transition.view,
                resizable: true,
                sortable: true,
                filterable: true,
                hidden: false,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.reporter'),
                valuePath: 'reporter_name',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: 'Select reporter',
                filterParam: 'reporter',
                model: 'user',
            },
            {
                label: this.intl.t('column.driver'),
                valuePath: 'driver_name',
                cellComponent: 'table/cell/anchor',
                permission: 'fleet-ops view driver',
                onClick: this.fuelReportActions.viewDriver,
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: 'Select driver',
                filterParam: 'driver',
                model: 'driver',
            },
            {
                label: this.intl.t('column.vehicle'),
                valuePath: 'vehicle_name',
                cellComponent: 'table/cell/anchor',
                permission: 'fleet-ops view vehicle',
                onClick: this.fuelReportActions.viewVehicle,
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: 'Select vehicle',
                filterParam: 'vehicle',
                model: 'vehicle',
                modelNamePath: 'displayName',
            },
            {
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/multi-option',
                filterOptions: ['draft', 'pending-approval', 'approved', 'rejected', 'revised', 'submitted', 'in-review', 'confirmed', 'processed', 'archived', 'cancelled'],
            },
            {
                label: this.intl.t('column.volume'),
                valuePath: 'volume',
                cellComponent: 'click-to-copy',
                resizable: true,
                sortable: true,
                filterable: true,
                hidden: false,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.odometer'),
                valuePath: 'odometer',
                cellComponent: 'click-to-copy',
                resizable: true,
                sortable: true,
                filterable: true,
                hidden: false,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.created-at'),
                valuePath: 'createdAt',
                sortParam: 'created_at',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/date',
            },
            {
                label: this.intl.t('column.updated-at'),
                valuePath: 'updatedAt',
                sortParam: 'updated_at',
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
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.fuel-report') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.fuel-report') }),
                        fn: this.fuelReportActions.transition.view,
                        permission: 'fleet-ops view fuel-report',
                    },
                    {
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.fuel-report') }),
                        fn: this.fuelReportActions.transition.edit,
                        permission: 'fleet-ops update fuel-report',
                    },
                    {
                        separator: true,
                    },
                    {
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.fuel-report') }),
                        fn: this.fuelReportActions.delete,
                        permission: 'fleet-ops delete fuel-report',
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
