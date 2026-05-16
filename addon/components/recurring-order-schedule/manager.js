import Component from '@glimmer/component';
import ObjectProxy from '@ember/object/proxy';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';

const STATUS_OPTIONS = [
    { value: null, label: 'All statuses' },
    { value: 'active', label: 'Active' },
    { value: 'paused', label: 'Paused' },
    { value: 'canceled', label: 'Canceled' },
];

export default class RecurringOrderScheduleManagerComponent extends Component {
    @service store;
    @service intl;
    @service recurringOrderScheduleActions;

    @tracked page = 1;
    @tracked limit = 12;
    @tracked sort = '-created_at';
    @tracked query = null;
    @tracked status = null;
    @tracked schedules = ObjectProxy.create({ content: [], meta: { total: 0, per_page: 12, current_page: 1, last_page: 1, from: null, to: null, time: 32 } });

    statusOptions = STATUS_OPTIONS;

    constructor() {
        super(...arguments);
        this.loadSchedules.perform();
    }

    get selectedStatusOption() {
        return this.statusOptions.find((option) => option.value === this.status) ?? this.statusOptions[0];
    }

    get actionButtons() {
        return [
            {
                icon: 'refresh',
                helpText: this.intl.t('common.refresh'),
                onClick: () => this.loadSchedules.perform(),
            },
            {
                text: 'New',
                type: 'primary',
                icon: 'plus',
                onClick: () =>
                    this.recurringOrderScheduleActions.modal.create(
                        {},
                        {},
                        {
                            refresh: false,
                            onSave: () => this.loadSchedules.perform(),
                        }
                    ),
            },
        ];
    }

    get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('column.id'),
                valuePath: 'public_id',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.name'),
                valuePath: 'name',
                resizable: true,
                sortable: true,
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
            },
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                width: 60,
                actions: [
                    {
                        label: 'View schedule',
                        icon: 'eye',
                        fn: this.viewSchedule,
                    },
                    {
                        label: 'Edit schedule',
                        icon: 'pencil',
                        fn: this.editSchedule,
                    },
                    {
                        label: 'Pause schedule',
                        icon: 'pause',
                        fn: this.pauseSchedule,
                        isVisible: (schedule) => schedule.status !== 'paused' && schedule.status !== 'canceled',
                    },
                    {
                        label: 'Resume schedule',
                        icon: 'play',
                        fn: this.resumeSchedule,
                        isVisible: (schedule) => schedule.status === 'paused',
                    },
                    {
                        label: 'Cancel future orders',
                        icon: 'ban',
                        fn: this.cancelFutureOrders,
                        isVisible: (schedule) => schedule.status !== 'canceled',
                    },
                    { separator: true },
                    {
                        label: 'Delete schedule',
                        icon: 'trash',
                        class: 'text-red-500',
                        fn: this.deleteSchedule,
                    },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
    }

    @task({ restartable: true }) *loadSchedules() {
        const params = {
            page: this.page,
            limit: this.limit,
            sort: this.sort,
        };

        if (this.query) {
            params.query = this.query;
        }

        if (this.status) {
            params.status = this.status;
        }

        this.schedules = yield this.store.query('recurring-order-schedule', params);
    }

    @task({ restartable: true }) *searchSchedules(event) {
        this.query = event.target.value || null;
        this.page = 1;
        yield timeout(250);
        yield this.loadSchedules.perform();
    }

    @action changePage(page) {
        this.page = page;
        this.loadSchedules.perform();
    }

    @action changeStatus(option) {
        this.status = option?.value ?? null;
        this.page = 1;
        this.loadSchedules.perform();
    }

    @action handleSort(sort) {
        this.sort = sort || '-created_at';
        this.page = 1;
        this.loadSchedules.perform();
    }

    @action editSchedule(schedule) {
        return this.recurringOrderScheduleActions.modal.edit(schedule, {}, { refresh: false, onSave: () => this.loadSchedules.perform() });
    }

    @action viewSchedule(schedule) {
        return this.recurringOrderScheduleActions.modal.view(schedule);
    }

    @action pauseSchedule(schedule) {
        return this.recurringOrderScheduleActions.pause(schedule).then(() => this.loadSchedules.perform());
    }

    @action resumeSchedule(schedule) {
        return this.recurringOrderScheduleActions.resume(schedule).then(() => this.loadSchedules.perform());
    }

    @action cancelFutureOrders(schedule) {
        return this.recurringOrderScheduleActions.cancelFuture(schedule, { cancelGeneratedOrders: false }).then(() => this.loadSchedules.perform());
    }

    @action deleteSchedule(schedule) {
        return this.recurringOrderScheduleActions.delete(schedule, {}, { callback: () => this.loadSchedules.perform() });
    }
}
