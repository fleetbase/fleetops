import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import fleetOpsOptions from '../../../utils/fleet-ops-options';

export default class ManagementIssuesIndexController extends Controller {
    @service issueActions;
    @service intl;
    @tracked queryParams = [
        'page',
        'limit',
        'sort',
        'query',
        'public_id',
        'issue_id',
        'driver',
        'vehicle',
        'assignee',
        'reporter',
        'created_by',
        'updated_by',
        'status',
        'priority',
        'cateogry',
        'type',
    ];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked status;
    @tracked priority;
    @tracked type;
    @tracked category;
    @tracked vehicle;
    @tracked driver;
    @tracked assignee;
    @tracked reporter;
    @tracked table;
    @tracked actionButtons = [
        {
            icon: 'refresh',
            onClick: this.issueActions.refresh,
            helpText: this.intl.t('fleet-ops.common.reload-data'),
        },
        {
            text: 'New',
            type: 'primary',
            icon: 'plus',
            onClick: this.issueActions.transition.create,
        },
        {
            text: 'Import',
            type: 'magic',
            icon: 'upload',
            onClick: this.issueActions.import,
        },
        {
            text: 'Export',
            icon: 'long-arrow-up',
            iconClass: 'rotate-icon-45',
            wrapperClass: 'hidden md:flex',
            onClick: this.issueActions.export,
        },
    ];
    @tracked bulkActions = [
        {
            label: 'Delete selected...',
            class: 'text-red-500',
            fn: this.issueActions.bulkDelete,
        },
    ];
    @tracked columns = [
        {
            label: this.intl.t('fleet-ops.common.id'),
            valuePath: 'public_id',
            cellComponent: 'table/cell/anchor',
            action: this.issueActions.transition.view,
            permission: 'fleet-ops view issue',
            width: '110px',
            resizable: true,
            sortable: true,
        },
        {
            label: this.intl.t('fleet-ops.common.priority'),
            valuePath: 'priority',
            cellComponent: 'table/cell/status',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/multi-option',
            filterOptions: ['low', 'medium', 'high', 'critical', 'scheduled-maintenance', 'operational-suggestion'],
        },
        {
            label: this.intl.t('fleet-ops.common.type'),
            valuePath: 'type',
            width: '100px',
            humanize: true,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/select',
            filterOptionLabel: 'label',
            filterOptionValue: 'value',
            filterOptions: fleetOpsOptions('issueTypes'),
            placeholder: 'Select issue type',
        },
        {
            label: this.intl.t('fleet-ops.common.category'),
            valuePath: 'category',
            width: '120px',
            humanize: true,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/select',
            filterOptionLabel: 'label',
            filterOptionValue: 'value',
            filterOptions: fleetOpsOptions('issueCategories'),
            placeholder: 'Select issue category',
        },
        {
            label: this.intl.t('fleet-ops.common.reporter'),
            valuePath: 'reporter_name',
            width: '100px',
            permission: 'iam view user',
            resizable: true,
            sortable: true,
            filterable: true,
            hidden: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select reporter',
            filterParam: 'reporter',
            model: 'user',
        },
        {
            label: this.intl.t('fleet-ops.common.assignee'),
            valuePath: 'assignee_name',
            width: '100px',
            permission: 'iam view user',
            hidden: true,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select assignee',
            filterParam: 'assignee',
            model: 'user',
        },
        {
            label: this.intl.t('fleet-ops.common.driver'),
            valuePath: 'driver_name',
            width: '100px',
            cellComponent: 'table/cell/anchor',
            permission: 'fleet-ops view driver',
            onClick: this.issueActions.viewDriver,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select driver',
            filterParam: 'driver',
            model: 'driver',
        },
        {
            label: this.intl.t('fleet-ops.common.vehicle'),
            valuePath: 'vehicle_name',
            width: '100px',
            cellComponent: 'table/cell/anchor',
            permission: 'fleet-ops view vehicle',
            onClick: this.issueActions.viewVehicle,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select vehicle',
            filterParam: 'vehicle',
            model: 'vehicle',
            modelNamePath: 'displayName',
        },
        {
            label: this.intl.t('fleet-ops.common.status'),
            valuePath: 'status',
            cellComponent: 'table/cell/status',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/multi-option',
            filterOptions: ['pending', 'in-progress', 'backlogged', 'requires-update', 'in-review', 're-opened', 'duplicate', 'pending-review', 'escalated', 'completed', 'canceled'],
        },
        {
            label: this.intl.t('fleet-ops.common.created-at'),
            valuePath: 'createdAt',
            sortParam: 'created_at',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: this.intl.t('fleet-ops.common.updated-at'),
            valuePath: 'updatedAt',
            sortParam: 'updated_at',
            width: '120px',
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
            ddMenuLabel: 'Issue Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '10%',
            actions: [
                {
                    label: this.intl.t('fleet-ops.management.issues.index.view'),
                    fn: this.issueActions.transition.view,
                    permission: 'fleet-ops view issue',
                },
                {
                    label: this.intl.t('fleet-ops.management.issues.index.edit-issues'),
                    fn: this.issueActions.transition.edit,
                    permission: 'fleet-ops update issue',
                },
                {
                    separator: true,
                },
                {
                    label: this.intl.t('fleet-ops.management.issues.index.delete'),
                    fn: this.issueActions.delete,
                    permission: 'fleet-ops delete issue',
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
    ];
}
