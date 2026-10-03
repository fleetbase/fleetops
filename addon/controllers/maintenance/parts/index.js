import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class MaintenancePartsIndexController extends Controller {
    @service partActions;
    @service intl;
    @service appCache;

    @tracked queryParams = this.partActions.queryParamsFor(['type', 'status', 'page', 'limit', 'sort', 'query', 'public_id', 'created_at', 'updated_at']);
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked type;
    @tracked status;
    @tracked table;
    @tracked layout = this.appCache.get('fleetops:parts:layout', 'table');

    /* eslint-disable ember/no-side-effects */
    get actionButtons() {
        return [
            {
                id: 'more',
                component: 'dropdown-button',
                icon: 'display',
                size: 'xs',
                items: [
                    {
                        label: this.intl.t('common.table-view'),
                        icon: 'table-list',
                        onClick: () => {
                            this.layout = 'table';
                            this.appCache.set('fleetops:parts:layout', 'table');
                        },
                    },
                    {
                        label: this.intl.t('common.grid-view'),
                        icon: 'grip',
                        onClick: () => {
                            this.layout = 'grid';
                            this.appCache.set('fleetops:parts:layout', 'grid');
                        },
                    },
                ],
                renderInPlace: true,
                helpText: 'Change the layout',
            },
            { id: 'refresh', icon: 'refresh', onClick: this.partActions.refresh, helpText: this.intl.t('common.refresh') },
            { id: 'create', text: this.intl.t('common.new'), type: 'primary', icon: 'plus', onClick: this.partActions.transition.create, permission: 'fleet-ops create part' },
            { id: 'import', text: this.intl.t('common.import'), type: 'magic', icon: 'upload', onClick: this.partActions.import, permission: 'fleet-ops import part' },
            {
                id: 'export',
                text: this.intl.t('common.export'),
                icon: 'long-arrow-up',
                iconClass: 'rotate-icon-45',
                wrapperClass: 'hidden md:flex',
                onClick: this.partActions.export,
                permission: 'fleet-ops export part',
            },
        ];
    }

    get bulkActions() {
        return [{ id: 'bulk-delete', label: 'Delete selected...', class: 'text-red-500', fn: this.partActions.bulkDelete, permission: 'fleet-ops delete part' }];
    }

    get columns() {
        return [
            {
                id: 'name',
                label: this.intl.t('column.name'),
                valuePath: 'name',
                cellComponent: 'cell/part-identity',
                action: this.partActions.transition.view,
                permission: 'fleet-ops view part',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'name',
                filterComponent: 'filter/string',
            },
            {
                id: 'sku',
                label: this.intl.t('column.part-number'),
                valuePath: 'sku',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'sku',
                filterComponent: 'filter/string',
            },
            {
                id: 'type',
                label: this.intl.t('column.type'),
                valuePath: 'type',
                cellComponent: 'table/cell/base',
                humanize: true,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'type',
                filterComponent: 'filter/string',
            },
            {
                id: 'status',
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'status',
                filterComponent: 'filter/string',
            },
            { id: 'quantity-on-hand', label: this.intl.t('column.quantity-on-hand'), valuePath: 'quantity_on_hand', resizable: true, sortable: true },
            { id: 'unit-cost', label: this.intl.t('column.unit-cost'), valuePath: 'unit_cost', cellComponent: 'table/cell/currency', resizable: true, sortable: true },
            {
                id: 'created-at',
                label: this.intl.t('column.created-at'),
                valuePath: 'createdAt',
                sortParam: 'created_at',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/date',
            },
            {
                id: 'updated-at',
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
                id: 'row-actions',
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.part') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                actions: [
                    {
                        id: 'view',
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.part') }),
                        fn: this.partActions.transition.view,
                        permission: 'fleet-ops view part',
                    },
                    {
                        id: 'edit',
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.part') }),
                        fn: this.partActions.transition.edit,
                        permission: 'fleet-ops update part',
                    },
                    { separator: true },
                    {
                        id: 'delete',
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.part') }),
                        fn: this.partActions.delete,
                        permission: 'fleet-ops delete part',
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
