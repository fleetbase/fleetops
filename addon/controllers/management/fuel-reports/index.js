import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class ManagementFuelReportsIndexController extends Controller {
    @service fuelReportActions;
    @service intl;
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
    @tracked actionButtons = [
        {
            icon: 'refresh',
            onClick: this.fuelReportActions.refresh,
            helpText: this.intl.t('fleet-ops.common.reload-data'),
        },
        {
            text: 'New',
            type: 'primary',
            icon: 'plus',
            onClick: this.fuelReportActions.transition.create,
        },
        {
            text: 'Import',
            type: 'magic',
            icon: 'upload',
            onClick: this.fuelReportActions.import,
        },
        {
            text: 'Export',
            icon: 'long-arrow-up',
            iconClass: 'rotate-icon-45',
            wrapperClass: 'hidden md:flex',
            onClick: this.fuelReportActions.export,
        },
    ];
    @tracked bulkActions = [
        {
            label: 'Delete selected...',
            class: 'text-red-500',
            fn: this.fuelReportActions.bulkDelete,
        },
    ];
    @tracked columns = [
        {
            label: this.intl.t('fleet-ops.common.id'),
            valuePath: 'public_id',
            cellComponent: 'table/cell/anchor',
            action: this.fuelReportActions.transition.view,
            width: '130px',
            resizable: true,
            sortable: true,
            filterable: true,
            hidden: false,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.reporter'),
            valuePath: 'reporter_name',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select reporter',
            filterParam: 'reporter',
            model: 'user',
        },
        {
            label: this.intl.t('fleet-ops.common.driver'),
            valuePath: 'driver_name',
            width: '120px',
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
            label: this.intl.t('fleet-ops.common.vehicle'),
            valuePath: 'vehicle_name',
            width: '100px',
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
            label: this.intl.t('fleet-ops.common.status'),
            valuePath: 'status',
            cellComponent: 'table/cell/status',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/multi-option',
            filterOptions: ['draft', 'pending-approval', 'approved', 'rejected', 'revised', 'submitted', 'in-review', 'confirmed', 'processed', 'archived', 'cancelled'],
        },
        {
            label: this.intl.t('fleet-ops.common.volume'),
            valuePath: 'volume',
            width: '130px',
            cellComponent: 'click-to-copy',
            resizable: true,
            sortable: true,
            filterable: true,
            hidden: false,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.odometer'),
            valuePath: 'odometer',
            width: '130px',
            cellComponent: 'click-to-copy',
            resizable: true,
            sortable: true,
            filterable: true,
            hidden: false,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.created-at'),
            valuePath: 'createdAt',
            sortParam: 'created_at',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: this.intl.t('fleet-ops.common.updated-at'),
            valuePath: 'updatedAt',
            sortParam: 'updated_at',
            width: '120px',
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
            ddMenuLabel: 'Fuel Report Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '10%',
            actions: [
                {
                    label: this.intl.t('fleet-ops.management.fuel-reports.index.view'),
                    fn: this.fuelReportActions.transition.view,
                    permission: 'fleet-ops view fuel-report',
                },
                {
                    label: this.intl.t('fleet-ops.management.fuel-reports.index.edit-fuel'),
                    fn: this.fuelReportActions.transition.edit,
                    permission: 'fleet-ops update fuel-report',
                },
                {
                    separator: true,
                },
                {
                    label: this.intl.t('fleet-ops.management.fuel-reports.index.delete'),
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
