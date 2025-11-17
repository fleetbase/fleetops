import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import fleetOpsOptions from '../../../utils/fleet-ops-options';

export default class ManagementIssuesIndexController extends Controller {
    @service issueActions;
    @service tableContext;
    @service intl;

    /** query params */
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

    /** action buttons */
    get actionButtons() {
        return [
            {
                icon: 'refresh',
                onClick: this.issueActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
            {
                text: this.intl.t('common.new'),
                type: 'primary',
                icon: 'plus',
                onClick: this.issueActions.transition.create,
            },
            {
                text: this.intl.t('common.import'),
                type: 'magic',
                icon: 'upload',
                onClick: this.issueActions.import,
            },
            {
                text: this.intl.t('common.export'),
                icon: 'long-arrow-up',
                iconClass: 'rotate-icon-45',
                wrapperClass: 'hidden md:flex',
                onClick: this.issueActions.export,
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
                fn: this.issueActions.bulkDelete,
            },
        ];
    }

    /** columns */
    get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('column.id'),
                valuePath: 'public_id',
                cellComponent: 'table/cell/anchor',
                action: this.issueActions.transition.view,
                permission: 'fleet-ops view issue',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.priority'),
                valuePath: 'priority',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/multi-option',
                filterOptions: ['low', 'medium', 'high', 'critical', 'scheduled-maintenance', 'operational-suggestion'],
            },
            {
                label: this.intl.t('column.type'),
                valuePath: 'type',
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
                label: this.intl.t('column.category'),
                valuePath: 'category',
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
                label: this.intl.t('column.reporter'),
                valuePath: 'reporter_name',
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
                label: this.intl.t('column.assignee'),
                valuePath: 'assignee_name',
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
                label: this.intl.t('column.driver'),
                valuePath: 'driver_name',
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
                label: this.intl.t('column.vehicle'),
                valuePath: 'vehicle_name',
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
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/multi-option',
                filterOptions: ['pending', 'in-progress', 'backlogged', 'requires-update', 'in-review', 're-opened', 'duplicate', 'pending-review', 'escalated', 'completed', 'canceled'],
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
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.issue') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.issue') }),
                        fn: this.issueActions.transition.view,
                        permission: 'fleet-ops view issue',
                    },
                    {
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.issue') }),
                        fn: this.issueActions.transition.edit,
                        permission: 'fleet-ops update issue',
                    },
                    {
                        separator: true,
                    },
                    {
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.issue') }),
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
}
