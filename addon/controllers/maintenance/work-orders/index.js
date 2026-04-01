import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class MaintenanceWorkOrdersIndexController extends Controller {
    @service workOrderActions;
    @service intl;

    @tracked queryParams = ['status', 'priority', 'page', 'limit', 'sort', 'query', 'public_id', 'created_at', 'updated_at'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked status;
    @tracked priority;

    get actionButtons() {
        return [
            { icon: 'refresh', onClick: this.workOrderActions.refresh, helpText: this.intl.t('common.refresh') },
            { text: this.intl.t('common.new'), type: 'primary', icon: 'plus', onClick: this.workOrderActions.transition.create },
            { text: this.intl.t('common.import'), type: 'magic', icon: 'upload', onClick: this.workOrderActions.import },
            { text: this.intl.t('common.export'), icon: 'long-arrow-up', iconClass: 'rotate-icon-45', wrapperClass: 'hidden md:flex', onClick: this.workOrderActions.export },
        ];
    }

    get bulkActions() {
        return [{ label: 'Delete selected...', class: 'text-red-500', fn: this.workOrderActions.bulkDelete }];
    }

    get columns() {
        return [
            {
                label: this.intl.t('column.code'),
                valuePath: 'code',
                cellComponent: 'table/cell/anchor',
                cellClassNames: 'uppercase',
                action: this.workOrderActions.transition.view,
                permission: 'fleet-ops view work-order',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'code',
                filterComponent: 'filter/string',
            },
            { label: this.intl.t('column.subject'), valuePath: 'subject', resizable: true, sortable: true, filterable: true, filterParam: 'subject', filterComponent: 'filter/string' },
            {
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'status',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.priority'),
                valuePath: 'priority',
                cellComponent: 'table/cell/base',
                humanize: true,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'priority',
                filterComponent: 'filter/string',
            },
            { label: this.intl.t('column.assignee'), valuePath: 'assignee_name', resizable: true, sortable: false },
            { label: this.intl.t('column.due-at'), valuePath: 'dueAt', sortParam: 'due_at', resizable: true, sortable: true, filterable: true, filterComponent: 'filter/date' },
            { label: this.intl.t('column.created-at'), valuePath: 'createdAt', sortParam: 'created_at', resizable: true, sortable: true, filterable: true, filterComponent: 'filter/date' },
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
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.work-order') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.work-order') }),
                        fn: this.workOrderActions.transition.view,
                        permission: 'fleet-ops view work-order',
                    },
                    {
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.work-order') }),
                        fn: this.workOrderActions.transition.edit,
                        permission: 'fleet-ops update work-order',
                    },
                    { separator: true },
                    {
                        label: 'Send Work Order to Vendor',
                        fn: this.workOrderActions.sendEmail,
                        icon: 'paper-plane',
                        permission: 'fleet-ops update work-order',
                    },
                    { separator: true },
                    {
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.work-order') }),
                        fn: this.workOrderActions.delete,
                        permission: 'fleet-ops delete work-order',
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
