import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class MaintenanceSchedulesIndexController extends Controller {
    @service maintenanceScheduleActions;
    @service intl;
    @tracked queryParams = ['status', 'page', 'limit', 'sort', 'query', 'public_id', 'created_at', 'updated_at'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked status;

    get actionButtons() {
        return [
            { icon: 'refresh', onClick: this.maintenanceScheduleActions.refresh, helpText: this.intl.t('common.refresh') },
            { text: this.intl.t('common.new'), type: 'primary', icon: 'plus', onClick: this.maintenanceScheduleActions.transition.create },
            { text: this.intl.t('common.import'), type: 'magic', icon: 'upload', onClick: this.maintenanceScheduleActions.import },
            { text: this.intl.t('common.export'), icon: 'long-arrow-up', iconClass: 'rotate-icon-45', wrapperClass: 'hidden md:flex', onClick: this.maintenanceScheduleActions.export },
        ];
    }

    get bulkActions() {
        return [{ label: 'Delete selected...', class: 'text-red-500', fn: this.maintenanceScheduleActions.bulkDelete }];
    }

    get columns() {
        return [
            {
                label: this.intl.t('column.id'),
                valuePath: 'public_id',
                cellComponent: 'table/cell/anchor',
                cellClassNames: 'uppercase',
                action: this.maintenanceScheduleActions.transition.view,
                permission: 'fleet-ops view maintenance-schedule',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'public_id',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.name'),
                valuePath: 'name',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'name',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.subject'),
                valuePath: 'subject.name',
                resizable: true,
                sortable: false,
            },
            {
                label: this.intl.t('column.type'),
                valuePath: 'type',
                cellComponent: 'table/cell/base',
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
                label: this.intl.t('column.next-due'),
                valuePath: 'nextDueAtShort',
                sortParam: 'next_due_date',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/date',
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
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.maintenance-schedule') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.maintenance-schedule') }),
                        fn: this.maintenanceScheduleActions.transition.view,
                        permission: 'fleet-ops view maintenance-schedule',
                    },
                    {
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.maintenance-schedule') }),
                        fn: this.maintenanceScheduleActions.transition.edit,
                        permission: 'fleet-ops update maintenance-schedule',
                    },
                    {
                        label: this.intl.t('maintenance-schedule.actions.trigger-now'),
                        fn: this.maintenanceScheduleActions.triggerNow,
                        permission: 'fleet-ops update maintenance-schedule',
                    },
                    { separator: true },
                    {
                        label: this.intl.t('maintenance-schedule.actions.pause'),
                        fn: this.maintenanceScheduleActions.pause,
                        permission: 'fleet-ops update maintenance-schedule',
                    },
                    {
                        label: this.intl.t('maintenance-schedule.actions.resume'),
                        fn: this.maintenanceScheduleActions.resume,
                        permission: 'fleet-ops update maintenance-schedule',
                    },
                    { separator: true },
                    {
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.maintenance-schedule') }),
                        fn: this.maintenanceScheduleActions.delete,
                        permission: 'fleet-ops delete maintenance-schedule',
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
