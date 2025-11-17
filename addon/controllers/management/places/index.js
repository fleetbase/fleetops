import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class ManagementPlacesIndexController extends Controller {
    @service placeActions;
    @service tableContext;
    @service intl;

    /** query params */
    @tracked queryParams = ['name', 'page', 'limit', 'sort', 'query', 'public_id', 'country', 'phone', 'created_at', 'updated_at', 'city', 'neighborhood', 'state'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked postal_code;
    @tracked phone;
    @tracked city;
    @tracked name;
    @tracked state;
    @tracked country;
    @tracked neighborhood;

    /** action buttons */
    get actionButtons() {
        return [
            {
                icon: 'refresh',
                onClick: this.placeActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
            {
                text: this.intl.t('common.new'),
                type: 'primary',
                icon: 'plus',
                onClick: this.placeActions.transition.create,
            },
            {
                text: this.intl.t('common.import'),
                type: 'magic',
                icon: 'upload',
                onClick: this.placeActions.import,
            },
            {
                text: this.intl.t('common.export'),
                icon: 'long-arrow-up',
                iconClass: 'rotate-icon-45',
                wrapperClass: 'hidden md:flex',
                onClick: this.placeActions.export,
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
                fn: this.placeActions.bulkDelete,
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
                cellComponent: 'table/cell/anchor',
                cellClassNames: 'uppercase',
                action: this.placeActions.transition.view,
                permission: 'fleet-ops view place',
                hidden: true,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'name',
                filterComponent: 'filter/string',
            },
            {
                sticky: true,
                label: this.intl.t('column.address'),
                valuePath: 'address',
                cellComponent: 'table/cell/anchor',
                action: this.placeActions.transition.view,
                permission: 'fleet-ops view place',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'address',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.id'),
                valuePath: 'public_id',
                cellComponent: 'click-to-copy',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'id',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.city'),
                valuePath: 'city',
                cellComponent: 'table/cell/anchor',
                cellClassNames: 'uppercase',
                action: this.placeActions.transition.view,
                permission: 'fleet-ops view place',
                hidden: true,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'city',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.state'),
                valuePath: 'state',
                cellComponent: 'table/cell/anchor',
                cellClassNames: 'uppercase',
                action: this.placeActions.transition.view,
                permission: 'fleet-ops view place',
                hidden: true,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'state',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.postal-code'),
                valuePath: 'postal_code',
                cellComponent: 'table/cell/anchor',
                action: this.placeActions.transition.view,
                permission: 'fleet-ops view place',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.country'),
                valuePath: 'country_name',
                cellComponent: 'table/cell/base',
                cellClassNames: 'uppercase',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/country',
                filterParam: 'country',
            },
            {
                label: this.intl.t('column.neighborhood'),
                valuePath: 'neighborhood',
                cellComponent: 'table/cell/anchor',
                cellClassNames: 'uppercase',
                action: this.placeActions.transition.view,
                permission: 'fleet-ops view place',
                hidden: true,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'neighborhood',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.phone'),
                valuePath: 'phone',
                cellComponent: 'table/cell/base',
                hidden: true,
                resizable: true,
                sortable: true,
                filterable: true,
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
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.place') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.place') }),
                        fn: this.placeActions.transition.view,
                        permission: 'fleet-ops view place',
                    },
                    {
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.place') }),
                        fn: this.placeActions.transition.edit,
                        permission: 'fleet-ops update place',
                    },
                    {
                        separator: true,
                    },
                    {
                        label: this.intl.t('place.actions.locate-place', { resource: this.intl.t('resource.place') }),
                        fn: this.placeActions.locate,
                        permission: 'fleet-ops view place',
                    },
                    {
                        separator: true,
                    },
                    {
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.place') }),
                        fn: this.placeActions.delete,
                        permission: 'fleet-ops delete place',
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
