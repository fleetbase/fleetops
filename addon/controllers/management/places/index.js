import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class ManagementPlacesIndexController extends Controller {
    @service placeActions;
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
    @tracked actionButtons = [
        {
            icon: 'refresh',
            onClick: this.placeActions.refresh,
            helpText: this.intl.t('fleet-ops.common.reload-data'),
        },
        {
            text: 'New',
            type: 'primary',
            icon: 'plus',
            onClick: this.placeActions.transition.create,
        },
        {
            text: 'Import',
            type: 'magic',
            icon: 'upload',
            onClick: this.placeActions.import,
        },
        {
            text: 'Export',
            icon: 'long-arrow-up',
            iconClass: 'rotate-icon-45',
            wrapperClass: 'hidden md:flex',
            onClick: this.placeActions.export,
        },
    ];

    /** bulk action buttons */
    @tracked bulkActions = [
        {
            label: 'Delete selected...',
            class: 'text-red-500',
            fn: this.placeActions.bulkDelete,
        },
    ];

    /** columns */
    @tracked columns = [
        {
            label: this.intl.t('fleet-ops.common.name'),
            valuePath: 'name',
            width: '180px',
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
            label: this.intl.t('fleet-ops.common.address'),
            valuePath: 'address',
            cellComponent: 'table/cell/anchor',
            action: this.placeActions.transition.view,
            permission: 'fleet-ops view place',
            width: '320px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'address',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.id'),
            valuePath: 'public_id',
            width: '120px',
            cellComponent: 'click-to-copy',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'id',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.city'),
            valuePath: 'city',
            cellComponent: 'table/cell/anchor',
            cellClassNames: 'uppercase',
            action: this.placeActions.transition.view,
            permission: 'fleet-ops view place',
            width: '100px',
            hidden: true,
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'city',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.state'),
            valuePath: 'state',
            cellComponent: 'table/cell/anchor',
            cellClassNames: 'uppercase',
            action: this.placeActions.transition.view,
            permission: 'fleet-ops view place',
            width: '100px',
            hidden: true,
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'state',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.postal-code'),
            valuePath: 'postal_code',
            cellComponent: 'table/cell/anchor',
            action: this.placeActions.transition.view,
            permission: 'fleet-ops view place',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.country'),
            valuePath: 'country_name',
            cellComponent: 'table/cell/base',
            cellClassNames: 'uppercase',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/country',
            filterParam: 'country',
        },
        {
            label: this.intl.t('fleet-ops.common.neighborhood'),
            valuePath: 'neighborhood',
            cellComponent: 'table/cell/anchor',
            cellClassNames: 'uppercase',
            action: this.placeActions.transition.view,
            permission: 'fleet-ops view place',
            width: '100px',
            hidden: true,
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'neighborhood',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.phone'),
            valuePath: 'phone',
            cellComponent: 'table/cell/base',
            width: '120px',
            hidden: true,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.created-at'),
            valuePath: 'createdAt',
            sortParam: 'created_at',
            width: '10%',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: this.intl.t('fleet-ops.common.updated-at'),
            valuePath: 'updatedAt',
            sortParam: 'updated_at',
            width: '10%',
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
            ddMenuLabel: 'Place Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '10%',
            actions: [
                {
                    label: this.intl.t('fleet-ops.management.places.index.view-details'),
                    fn: this.placeActions.transition.view,
                    permission: 'fleet-ops view place',
                },
                {
                    label: this.intl.t('fleet-ops.management.places.index.edit-place'),
                    fn: this.placeActions.transition.edit,
                    permission: 'fleet-ops update place',
                },
                {
                    separator: true,
                },
                {
                    label: this.intl.t('fleet-ops.management.places.index.view-place'),
                    fn: this.placeActions.locate,
                    permission: 'fleet-ops view place',
                },
                {
                    separator: true,
                },
                {
                    label: this.intl.t('fleet-ops.management.places.index.delete'),
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
