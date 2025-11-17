import ManagementContactsIndexController from './index';
import { inject as service } from '@ember/service';

export default class ManagementContactsCustomersController extends ManagementContactsIndexController {
    @service('customerActions') contactActions;

    /** columns */
    get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('column.name'),
                valuePath: 'name',

                cellComponent: 'table/cell/media-name',
                action: this.contactActions.transition.view,
                permission: 'fleet-ops view contact',
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
                label: this.intl.t('column.title'),
                valuePath: 'title',
                cellComponent: 'click-to-copy',

                resizable: true,
                sortable: true,
                filterable: true,
                hidden: true,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.email'),
                valuePath: 'email',
                cellComponent: 'click-to-copy',

                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.phone'),
                valuePath: 'phone',
                cellComponent: 'click-to-copy',

                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.address'),
                valuePath: 'address',
                cellComponent: 'table/cell/anchor',
                action: this.contactActions.viewPlace,

                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'address',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.created'),
                valuePath: 'createdAt',
                sortParam: 'created_at',

                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/date',
            },
            {
                label: this.intl.t('column.updated'),
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
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.customer') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.customer') }),
                        fn: this.contactActions.transition.view,
                        permission: 'fleet-ops view contact',
                    },
                    {
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.customer') }),
                        fn: this.contactActions.transition.edit,
                        permission: 'fleet-ops update contact',
                    },
                    {
                        separator: true,
                    },
                    {
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.customer') }),
                        fn: this.contactActions.delete,
                        permission: 'fleet-ops delete contact',
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
