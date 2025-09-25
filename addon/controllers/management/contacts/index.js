import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class ManagementContactsIndexController extends Controller {
    @service contactActions;
    @service intl;
    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'public_id', 'internal_id', 'created_by', 'updated_by', 'status', 'title', 'email', 'phone'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked internal_id;
    @tracked title;
    @tracked email;
    @tracked phone;
    @tracked status;
    @tracked table;
    @tracked actionButtons = [
        {
            icon: 'refresh',
            onClick: this.contactActions.refresh,
            helpText: this.intl.t('fleet-ops.common.reload-data'),
        },
        {
            text: 'New',
            type: 'primary',
            icon: 'plus',
            onClick: this.contactActions.transition.create,
        },
        {
            text: 'Import',
            type: 'magic',
            icon: 'upload',
            onClick: this.contactActions.import,
        },
        {
            text: 'Export',
            icon: 'long-arrow-up',
            iconClass: 'rotate-icon-45',
            wrapperClass: 'hidden md:flex',
            onClick: this.contactActions.export,
        },
    ];
    @tracked bulkActions = [
        {
            label: 'Delete selected...',
            class: 'text-red-500',
            fn: this.contactActions.bulkDelete,
        },
    ];
    @tracked columns = [
        {
            label: this.intl.t('fleet-ops.common.name'),
            valuePath: 'name',
            width: '140px',
            cellComponent: 'table/cell/media-name',
            action: this.contactActions.transition.view,
            permission: 'fleet-ops view contact',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.id'),
            valuePath: 'public_id',
            cellComponent: 'click-to-copy',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.internal-id'),
            valuePath: 'internal_id',
            cellComponent: 'click-to-copy',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.title'),
            valuePath: 'title',
            cellComponent: 'click-to-copy',
            width: '80px',
            resizable: true,
            sortable: true,
            filterable: true,
            hidden: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.email'),
            valuePath: 'email',
            cellComponent: 'click-to-copy',
            width: '150px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.phone'),
            valuePath: 'phone',
            cellComponent: 'click-to-copy',
            width: '130px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.address'),
            valuePath: 'address',
            cellComponent: 'table/cell/anchor',
            action: this.contactActions.viewPlace,
            width: '170px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'address',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.management.contacts.index.created'),
            valuePath: 'createdAt',
            sortParam: 'created_at',
            width: '160px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: this.intl.t('fleet-ops.management.contacts.index.updated'),
            valuePath: 'updatedAt',
            sortParam: 'updated_at',
            width: '130px',
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
            ddMenuLabel: 'Contact Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '9%',
            actions: [
                {
                    label: this.intl.t('fleet-ops.management.contacts.index.view-contact'),
                    fn: this.contactActions.transition.view,
                    permission: 'fleet-ops view contact',
                },
                {
                    label: this.intl.t('fleet-ops.management.contacts.index.edit-contact'),
                    fn: this.contactActions.transition.edit,
                    permission: 'fleet-ops update contact',
                },
                {
                    separator: true,
                },
                {
                    label: this.intl.t('fleet-ops.management.contacts.index.delete-contact'),
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
