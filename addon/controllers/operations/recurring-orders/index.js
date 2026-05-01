import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class OperationsRecurringOrdersIndexController extends Controller {
    @service recurringOrderScheduleActions;
    @service intl;

    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'status', 'public_id', 'created_at', 'updated_at'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked status;
    @tracked public_id;
    @tracked created_at;
    @tracked updated_at;

    get scheduleActions() {
        return this.recurringOrderScheduleActions;
    }

    get actionButtons() {
        return [
            { icon: 'refresh', onClick: this.recurringOrderScheduleActions.refresh, helpText: this.intl.t('common.refresh') },
            { text: this.intl.t('common.new'), type: 'primary', icon: 'plus', onClick: this.recurringOrderScheduleActions.transition.create },
        ];
    }

    get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('column.id'),
                valuePath: 'public_id',
                cellComponent: 'table/cell/link-to',
                route: 'operations.recurring-orders.index.details',
                onLinkClick: this.recurringOrderScheduleActions.transition.view,
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.name'),
                valuePath: 'name',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.customer'),
                valuePath: 'customer.name',
                cellComponent: 'table/cell/base',
                resizable: true,
            },
            {
                label: this.intl.t('column.type'),
                valuePath: 'order_config.name',
                cellComponent: 'table/cell/base',
                resizable: true,
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
                label: 'Next Occurrence',
                valuePath: 'next_occurrence_at',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.created-at'),
                valuePath: 'created_at',
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
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                actions: [
                    { label: 'View', fn: this.recurringOrderScheduleActions.transition.view },
                    { label: 'Edit', fn: this.recurringOrderScheduleActions.transition.edit },
                    { label: 'Pause', fn: this.recurringOrderScheduleActions.pause },
                    { label: 'Resume', fn: this.recurringOrderScheduleActions.resume },
                    { label: 'Delete', class: 'text-red-500', fn: this.recurringOrderScheduleActions.delete },
                ],
            },
        ];
    }
}
