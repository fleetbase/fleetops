import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class MaintenanceMaintenancesIndexController extends Controller {
    @service maintenanceActions;
    @service intl;

    /** query params */
    @tracked queryParams = ['type', 'status', 'priority', 'page', 'limit', 'sort', 'query', 'public_id', 'created_at', 'updated_at'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked type;
    @tracked status;
    @tracked priority;

    /** action buttons */
    get actionButtons() {
        return [
            {
                icon: 'refresh',
                onClick: this.maintenanceActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
            {
                text: this.intl.t('common.new'),
                type: 'primary',
                icon: 'plus',
                onClick: this.maintenanceActions.transition.create,
            },
            {
                text: this.intl.t('common.import'),
                type: 'magic',
                icon: 'upload',
                onClick: this.maintenanceActions.import,
            },
            {
                text: this.intl.t('common.export'),
                icon: 'long-arrow-up',
                iconClass: 'rotate-icon-45',
                wrapperClass: 'hidden md:flex',
                onClick: this.maintenanceActions.export,
            },
        ];
    }

    /** bulk action buttons */
    get bulkActions() {
        return [
            {
                label: 'Delete selected...',
                class: 'text-red-500',
                fn: this.maintenanceActions.bulkDelete,
            },
        ];
    }

    /** columns */
    get columns() {
        return [
            {
                label: this.intl.t('column.summary'),
                valuePath: 'summary',
                cellComponent: 'table/cell/anchor',
                cellClassNames: 'uppercase',
                action: this.maintenanceActions.transition.view,
                permission: 'fleet-ops view maintenance',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'summary',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.asset'),
                valuePath: 'maintainable.name',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.performed-by'),
                valuePath: 'performed_by.name',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.type'),
                valuePath: 'type',
                cellComponent: 'table/cell/base',
                humanize: true,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'type',
                filterComponent: 'filter/string',
            },
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
            {
                label: this.intl.t('column.scheduled-at'),
                valuePath: 'scheduledAt',
                sortParam: 'scheduled_at',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/date',
            },
            {
                label: this.intl.t('column.total-cost'),
                valuePath: 'total_cost',
                cellComponent: 'table/cell/currency',
                resizable: true,
                sortable: true,
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
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.maintenance') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.maintenance') }),
                        fn: this.maintenanceActions.transition.view,
                        permission: 'fleet-ops view maintenance',
                    },
                    {
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.maintenance') }),
                        fn: this.maintenanceActions.transition.edit,
                        permission: 'fleet-ops update maintenance',
                    },
                    { separator: true },
                    {
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.maintenance') }),
                        fn: this.maintenanceActions.delete,
                        permission: 'fleet-ops delete maintenance',
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
