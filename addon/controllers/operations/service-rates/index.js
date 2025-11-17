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
    get actionButtons() {
        return [
            {
                icon: 'refresh',
                onClick: this.serviceRateActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
            {
                text: this.intl.t('common.new'),
                type: 'primary',
                icon: 'plus',
                onClick: this.serviceRateActions.transition.create,
            },
            {
                text: this.intl.t('common.export'),
                icon: 'long-arrow-up',
                iconClass: 'rotate-icon-45',
                wrapperClass: 'hidden md:flex',
                onClick: this.serviceRateActions.export,
            },
        ];
    }

    /** bulk action buttons */
    get bulkActions() {
        return [
            {
                label: 'Delete selected...',
                class: 'text-red-500',
                fn: this.serviceRateActions.bulkDelete,
            },
        ];
    }

    /** columns **/
    get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('column.id'),
                valuePath: 'public_id',
                cellComponent: 'table/cell/anchor',
                permission: 'fleet-ops view service-rate',
                onClick: this.serviceRateActions.transition.view,
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.service'),
                valuePath: 'service_name',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
                filterable: false,
            },
            {
                label: this.intl.t('column.service-area'),
                valuePath: 'service_area.name',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: 'Select service area',
                filterParam: 'service_area',
                model: 'service-area',
            },
            {
                label: this.intl.t('column.zone'),
                valuePath: 'zone.name',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: 'Select zone',
                filterParam: 'zone',
                model: 'zone',
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
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    {
                        label: this.intl.t('column.edit-service'),
                        fn: this.serviceRateActions.transition.edit,
                        permission: 'fleet-ops view service-rate',
                    },
                    {
                        label: this.intl.t('column.delete-service'),
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
}
