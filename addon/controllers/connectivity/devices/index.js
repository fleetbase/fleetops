import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import fleetOpsOptions from '../../../utils/fleet-ops-options';

export default class ConnectivityDevicesIndexController extends Controller {
    @service deviceActions;
    @service telematicActions;
    @service intl;

    /** query params */
    @tracked queryParams = ['name', 'page', 'limit', 'sort', 'query', 'public_id', 'created_at', 'updated_at'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked name;

    /** action buttons */
    @tracked actionButtons = [
        {
            icon: 'refresh',
            onClick: this.deviceActions.refresh,
            helpText: this.intl.t('common.refresh'),
        },
        {
            text: this.intl.t('common.new'),
            type: 'primary',
            icon: 'plus',
            onClick: this.deviceActions.transition.create,
        },
        {
            text: this.intl.t('common.import'),
            type: 'magic',
            icon: 'upload',
            onClick: this.deviceActions.import,
        },
        {
            text: this.intl.t('common.export'),
            icon: 'long-arrow-up',
            iconClass: 'rotate-icon-45',
            wrapperClass: 'hidden md:flex',
            onClick: this.deviceActions.export,
        },
    ];

    /** bulk action buttons */
    @tracked bulkActions = [
        {
            label: 'Delete selected...',
            class: 'text-red-500',
            fn: this.deviceActions.bulkDelete,
        },
    ];

    /** columns */
    @tracked columns = [
        {
            sticky: true,
            label: this.intl.t('column.name'),
            valuePath: 'displayName',
            cellComponent: 'table/cell/anchor',
            action: this.deviceActions.transition.view,
            permission: 'fleet-ops view device',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'name',
            filterComponent: 'filter/string',
        },
        {
            label: 'Telematic',
            valuePath: 'telematic.provider',
            cellComponent: 'table/cell/anchor',
            action: this.telematicActions.transition.view,
            permission: 'fleet-ops view telematic',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select telematic',
            filterParam: 'telematic',
            model: 'telematic',
        },
        {
            label: 'Type',
            valuePath: 'type',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'type',
            filterComponent: 'filter/multi-option',
            filterOptions: fleetOpsOptions('deviceTypes'),
        },
        {
            label: 'Serial Number',
            valuePath: 'serial_number',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'serial_number',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('column.status'),
            valuePath: 'status',
            cellComponent: 'table/cell/status',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/multi-option',
            filterOptions: fleetOpsOptions('deviceStatuses'),
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
            ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.device') }),
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            sticky: 'right',
            width: 60,
            actions: [
                {
                    label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.device') }),
                    fn: this.deviceActions.transition.view,
                    permission: 'fleet-ops view device',
                },
                {
                    label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.device') }),
                    fn: this.deviceActions.transition.edit,
                    permission: 'fleet-ops update device',
                },
                {
                    separator: true,
                },
                {
                    label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.device') }),
                    fn: this.deviceActions.delete,
                    permission: 'fleet-ops delete device',
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
    ];
}
