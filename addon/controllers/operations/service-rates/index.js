import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { get } from '@ember/object';
import { buildIdentityStub } from '../../../utils/identity-cell-resource';
import relationValue from '../../../utils/relation-value';
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
                cellComponent: 'cell/service-area-identity',
                resourcePath: (rate) => relationValue(rate, 'service_area') ?? buildIdentityStub(rate, { type: 'service-area', name: rate.service_area_name ?? get(rate, 'service_area.name'), load: () => rate.get('service_area') }),
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
                cellComponent: 'cell/zone-identity',
                resourcePath: (rate) => relationValue(rate, 'zone') ?? buildIdentityStub(rate, { type: 'zone', name: rate.zone_name ?? get(rate, 'zone.name'), load: () => rate.get('zone') }),
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
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.service-rate') }),
                        icon: 'eye',
                        fn: this.serviceRateActions.transition.view,
                        permission: 'fleet-ops view service-rate',
                    },
                    {
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.service-rate') }),
                        icon: 'pencil',
                        fn: this.serviceRateActions.transition.edit,
                        permission: 'fleet-ops view service-rate',
                    },
                    {
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.service-rate') }),
                        icon: 'trash',
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
