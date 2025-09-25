import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import fleetOpsOptions from '../../../utils/fleet-ops-options';

export default class ManagementVendorsIndexController extends Controller {
    @service vendorActions;
    @service intl;
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
    @tracked rows = [];
    @tracked table;
    @tracked actionButtons = [
        {
            icon: 'refresh',
            onClick: this.vendorActions.refresh,
            helpText: this.intl.t('fleet-ops.common.reload-data'),
        },
        {
            text: 'New',
            type: 'primary',
            icon: 'plus',
            onClick: this.vendorActions.transition.create,
        },
        {
            text: 'Import',
            type: 'magic',
            icon: 'upload',
            onClick: this.vendorActions.import,
        },
        {
            text: 'Export',
            icon: 'long-arrow-up',
            iconClass: 'rotate-icon-45',
            wrapperClass: 'hidden md:flex',
            onClick: this.vendorActions.export,
        },
    ];
    @tracked bulkActions = [
        {
            label: 'Delete selected...',
            class: 'text-red-500',
            fn: this.vendorActions.bulkDelete,
        },
    ];
    @tracked columns = [
        {
            label: this.intl.t('fleet-ops.common.name'),
            valuePath: 'name',
            width: '190px',
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
            label: this.intl.t('fleet-ops.common.id'),
            valuePath: 'public_id',
            cellComponent: 'click-to-copy',
            width: '110px',
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
            label: this.intl.t('fleet-ops.common.email'),
            valuePath: 'email',
            cellComponent: 'click-to-copy',
            width: '80px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.website-url'),
            valuePath: 'website_url',
            cellComponent: 'click-to-copy',
            width: '80px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.phone'),
            valuePath: 'phone',
            cellComponent: 'click-to-copy',
            width: '80px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.address'),
            valuePath: 'address',
            cellComponent: 'table/cell/anchor',
            action: this.vendorActions.viewPlace,
            width: '170px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'address',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.type'),
            valuePath: 'prettyType',
            humanize: true,
            width: '150px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'type',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.country'),
            valuePath: 'country',
            cellComponent: 'table/cell/base',
            cellClassNames: 'uppercase',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            hidden: true,
            filterComponent: 'filter/country',
            filterParam: 'country',
        },
        {
            label: this.intl.t('fleet-ops.common.created-at'),
            valuePath: 'createdAt',
            sortParam: 'created_at',
            width: '170px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: this.intl.t('fleet-ops.common.updated-at'),
            valuePath: 'updatedAt',
            sortParam: 'updated_at',
            width: '170px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: this.intl.t('fleet-ops.common.status'),
            valuePath: 'status',
            cellComponent: 'table/cell/status',
            width: '100px',
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
            ddMenuLabel: 'Vendor Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '7%',
            actions: [
                {
                    label: this.intl.t('fleet-ops.management.vendors.index.view-vendor'),
                    fn: this.vendorActions.transition.view,
                    permission: 'fleet-ops view vendor',
                },
                {
                    label: this.intl.t('fleet-ops.management.vendors.index.edit-vendor'),
                    fn: this.vendorActions.transition.edit,
                    permission: 'fleet-ops update vendor',
                },
                {
                    separator: true,
                },
                {
                    label: this.intl.t('fleet-ops.management.vendors.index.delete-vendor'),
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
