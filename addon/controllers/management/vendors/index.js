import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { buildIdentityStub } from '../../../utils/identity-cell-resource';
import relationValue from '../../../utils/relation-value';
import fleetOpsOptions from '../../../utils/fleet-ops-options';

export default class ManagementVendorsIndexController extends Controller {
    @service vendorActions;
    @service tableContext;
    @service intl;
    @service appCache;

    /** query params */
    @tracked queryParams = this.vendorActions.queryParamsFor([
        'page',
        'limit',
        'sort',
        'query',
        'public_id',
        'internal_id',
        'created_by',
        'updated_by',
        'status',
        'name',
        'email',
        'phone',
        'type',
        'country',
        'address',
        'website_url',
    ]);
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked internal_id;
    @tracked status;
    @tracked type;
    @tracked name;
    @tracked website_url;
    @tracked phone;
    @tracked email;
    @tracked country;
    @tracked table;
    @tracked layout = this.appCache.get(this.layoutCacheKey, 'table');

    /** cache key that remembers the chosen layout per listing */
    get layoutCacheKey() {
        return 'fleetops:vendors:layout';
    }

    /** action buttons */
    get actionButtons() {
        return [
            {
                id: 'more',
                component: 'dropdown-button',
                icon: 'display',
                size: 'xs',
                items: [
                    {
                        id: 'table-view',
                        label: this.intl.t('common.table-view'),
                        icon: 'table-list',
                        onClick: () => this.setLayout('table'),
                    },
                    {
                        id: 'grid-view',
                        label: this.intl.t('common.grid-view'),
                        icon: 'grip',
                        onClick: () => this.setLayout('grid'),
                    },
                ],
                renderInPlace: true,
                helpText: this.intl.t('common.change-layout'),
            },
            {
                id: 'refresh',
                icon: 'refresh',
                onClick: this.vendorActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
            {
                id: 'create',
                text: this.intl.t('common.new'),
                type: 'primary',
                icon: 'plus',
                onClick: this.vendorActions.transition.create,
                permission: 'fleet-ops create vendor',
            },
            {
                id: 'import',
                text: this.intl.t('common.import'),
                type: 'magic',
                icon: 'upload',
                onClick: this.vendorActions.import,
                permission: 'fleet-ops import vendor',
            },
            {
                id: 'export',
                text: this.intl.t('common.export'),
                icon: 'long-arrow-up',
                iconClass: 'rotate-icon-45',
                wrapperClass: 'hidden md:flex',
                onClick: this.vendorActions.export,
                permission: 'fleet-ops export vendor',
            },
        ];
    }

    /** bulk actions */
    get bulkActions() {
        const selected = this.tableContext.getSelectedRows();

        return [
            {
                id: 'bulk-delete',
                label: this.intl.t('common.delete-selected-count', { count: selected.length }),
                class: 'text-red-500',
                fn: this.vendorActions.bulkDelete,
                permission: 'fleet-ops delete vendor',
            },
        ];
    }

    /** columns */
    get columns() {
        return [
            {
                id: 'name',
                sticky: true,
                label: this.intl.t('column.name'),
                valuePath: 'name',
                cellComponent: 'cell/vendor-identity',
                mediaPath: 'logo_url',
                action: this.vendorActions.transition.view,
                permission: 'fleet-ops view vendor',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                id: 'public-id',
                label: this.intl.t('column.id'),
                valuePath: 'public_id',
                cellComponent: 'click-to-copy',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                id: 'internal-id',
                label: this.intl.t('column.internal-id'),
                valuePath: 'internal_id',
                cellComponent: 'click-to-copy',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                id: 'email',
                label: this.intl.t('column.email'),
                valuePath: 'email',
                cellComponent: 'click-to-copy',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                id: 'website-url',
                label: this.intl.t('column.website-url'),
                valuePath: 'website_url',
                cellComponent: 'click-to-copy',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                id: 'phone',
                label: this.intl.t('column.phone'),
                valuePath: 'phone',
                cellComponent: 'click-to-copy',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                id: 'address',
                label: this.intl.t('column.address'),
                valuePath: 'address',
                cellComponent: 'cell/place-identity',
                permission: 'fleet-ops view place',
                resourcePath: (vendor) => relationValue(vendor, 'place') ?? buildIdentityStub(vendor, { type: 'place', nameKey: 'address', load: () => vendor.get('place') }),
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'address',
                filterComponent: 'filter/string',
            },
            {
                id: 'pretty-type',
                label: this.intl.t('column.type'),
                valuePath: 'prettyType',
                humanize: true,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'type',
                filterComponent: 'filter/string',
            },
            {
                id: 'country',
                label: this.intl.t('column.country'),
                valuePath: 'country',
                cellComponent: 'table/cell/base',
                cellClassNames: 'uppercase',
                resizable: true,
                sortable: true,
                filterable: true,
                hidden: true,
                filterComponent: 'filter/country',
                filterParam: 'country',
            },
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
                id: 'status',
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/multi-option',
                filterOptionLabel: 'label',
                filterOptionValue: 'value',
                filterOptions: fleetOpsOptions('vendorStatuses'),
            },
            {
                id: 'row-actions',
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.vendor') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    {
                        id: 'view',
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.vendor') }),
                        fn: this.vendorActions.transition.view,
                        permission: 'fleet-ops view vendor',
                    },
                    {
                        id: 'edit',
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.vendor') }),
                        fn: this.vendorActions.transition.edit,
                        permission: 'fleet-ops update vendor',
                    },
                    {
                        separator: true,
                    },
                    {
                        id: 'delete',
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.vendor') }),
                        fn: this.vendorActions.delete,
                        permission: 'fleet-ops delete vendor',
                    },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
    }

    @action setLayout(layout) {
        this.layout = layout;
        this.appCache.set(this.layoutCacheKey, layout);
    }
}
