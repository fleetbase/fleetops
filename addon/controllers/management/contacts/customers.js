import ManagementContactsIndexController from './index';
import { inject as service } from '@ember/service';
import { buildIdentityStub } from '../../../utils/identity-cell-resource';
import relationValue from '../../../utils/relation-value';

export default class ManagementContactsCustomersController extends ManagementContactsIndexController {
    @service('customerActions') contactActions;

    get customerActions() {
        return this.contactActions;
    }

    get layoutCacheKey() {
        return 'fleetops:customers:layout';
    }

    /** columns */
    get columns() {
        return [
            {
                id: 'name',
                sticky: true,
                label: this.intl.t('column.name'),
                valuePath: 'name',

                cellComponent: 'cell/customer-identity',
                action: this.customerActions.transition.view,
                permission: 'fleet-ops view contact',
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
                id: 'title',
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
                id: 'email',
                label: this.intl.t('column.email'),
                valuePath: 'email',
                cellComponent: 'click-to-copy',

                resizable: true,
                sortable: true,
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
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                id: 'address',
                label: this.intl.t('column.address'),
                valuePath: 'address',
                cellComponent: 'cell/place-identity',
                permission: 'fleet-ops view place',
                resourcePath: (customer) => relationValue(customer, 'place') ?? buildIdentityStub(customer, { type: 'place', nameKey: 'address', load: () => customer.get('place') }),
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'address',
                filterComponent: 'filter/string',
            },
            {
                id: 'created-at',
                label: this.intl.t('column.created'),
                valuePath: 'createdAt',
                sortParam: 'created_at',

                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/date',
            },
            {
                id: 'updated-at',
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
                id: 'row-actions',
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
                        id: 'view',
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.customer') }),
                        icon: 'eye',
                        fn: this.customerActions.transition.view,
                        permission: 'fleet-ops view contact',
                    },
                    {
                        id: 'edit',
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.customer') }),
                        icon: 'pencil',
                        fn: this.customerActions.transition.edit,
                        permission: 'fleet-ops update contact',
                    },
                    {
                        separator: true,
                    },
                    ...this.customerActions.accountRowActionItems(),
                    {
                        separator: true,
                    },
                    {
                        id: 'delete',
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.customer') }),
                        icon: 'trash',
                        fn: this.customerActions.delete,
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
