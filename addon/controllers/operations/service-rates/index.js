import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class OperationsServiceRatesIndexController extends Controller {
    @service serviceRateActions;
    @service intl;

    /** query params */
    @tracked queryParams = ['page', 'query', 'limit', 'sort', 'zone', 'service_area'];
    @tracked page = 1;
    @tracked limit;
    @tracked query;
    @tracked sort = '-created_at';

    /** action buttons */
    @tracked actionButtons = [
        {
            icon: 'refresh',
            onClick: this.serviceRateActions.refresh,
            helpText: this.intl.t('fleet-ops.common.reload-data'),
        },
        {
            text: 'New',
            type: 'primary',
            icon: 'plus',
            onClick: this.serviceRateActions.transition.create,
        },
        {
            text: 'Export',
            icon: 'long-arrow-up',
            iconClass: 'rotate-icon-45',
            wrapperClass: 'hidden md:flex',
            onClick: this.serviceRateActions.export,
        },
    ];

    /** bulk action buttons */
    @tracked bulkActions = [
        {
            label: 'Delete selected...',
            class: 'text-red-500',
            fn: this.serviceRateActions.bulkDelete,
        },
    ];

    /** columns **/
    @tracked columns = [
        {
            label: this.intl.t('fleet-ops.common.id'),
            valuePath: 'public_id',
            width: '150px',
            cellComponent: 'table/cell/anchor',
            permission: 'fleet-ops view service-rate',
            onClick: this.serviceRateActions.transition.view,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.service'),
            valuePath: 'service_name',
            cellComponent: 'table/cell/base',
            width: '125px',
            resizable: true,
            sortable: true,
            filterable: false,
        },
        {
            label: this.intl.t('fleet-ops.common.service-area'),
            valuePath: 'service_area.name',
            cellComponent: 'table/cell/base',
            width: '125px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select service area',
            filterParam: 'service_area',
            model: 'service-area',
        },
        {
            label: this.intl.t('fleet-ops.common.zone'),
            valuePath: 'zone.name',
            cellComponent: 'table/cell/base',
            width: '125px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select zone',
            filterParam: 'zone',
            model: 'zone',
        },
        {
            label: this.intl.t('fleet-ops.common.created-at'),
            valuePath: 'createdAt',
            sortParam: 'created_at',
            width: '125px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: this.intl.t('fleet-ops.common.updated-at'),
            valuePath: 'updatedAt',
            sortParam: 'updated_at',
            width: '125px',
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
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '10%',
            actions: [
                {
                    label: this.intl.t('fleet-ops.operations.service-rates.index.edit-service'),
                    fn: this.serviceRateActions.transition.edit,
                    permission: 'fleet-ops view service-rate',
                },
                {
                    label: this.intl.t('fleet-ops.operations.service-rates.index.delete-service'),
                    fn: this.serviceRateActions.delete,
                    permission: 'fleet-ops delete service-rate',
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
    ];
}
