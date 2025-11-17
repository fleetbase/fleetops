import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import fleetOpsOptions from '../../../utils/fleet-ops-options';

export default class ManagementVendorsIndexController extends Controller {
    @service vendorActions;
    @service tableContext;
    @service intl;

    /** query params */
    @tracked queryParams = [
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
    ];
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

    /** action buttons */
    get actionButtons() {
        return [
            {
                icon: 'refresh',
                onClick: this.vendorActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
            {
                text: this.intl.t('common.new'),
                type: 'primary',
                icon: 'plus',
                onClick: this.vendorActions.transition.create,
            },
            {
                text: this.intl.t('common.import'),
                type: 'magic',
                icon: 'upload',
                onClick: this.vendorActions.import,
            },
            {
                text: this.intl.t('common.export'),
                icon: 'long-arrow-up',
                iconClass: 'rotate-icon-45',
                wrapperClass: 'hidden md:flex',
                onClick: this.vendorActions.export,
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
                fn: this.vendorActions.bulkDelete,
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
                cellComponent: 'table/cell/media-name',
                mediaPath: 'logo_url',
                action: this.vendorActions.transition.view,
                permission: 'fleet-ops view vendor',
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
                label: this.intl.t('column.address'),
                valuePath: 'address',
                cellComponent: 'table/cell/anchor',
                action: this.vendorActions.viewPlace,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'address',
                filterComponent: 'filter/string',
            },
            {
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
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.vendor') }),
                        fn: this.vendorActions.transition.view,
                        permission: 'fleet-ops view vendor',
                    },
                    {
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.vendor') }),
                        fn: this.vendorActions.transition.edit,
                        permission: 'fleet-ops update vendor',
                    },
                    {
                        separator: true,
                    },
                    {
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
}
