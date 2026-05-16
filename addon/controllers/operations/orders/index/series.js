import Controller, { inject as controller } from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';

const STATUS_OPTIONS = [
    { value: null, label: 'All statuses' },
    { value: 'active', label: 'Active' },
    { value: 'paused', label: 'Paused' },
    { value: 'canceled', label: 'Ended' },
];

export default class OperationsOrdersIndexSeriesController extends Controller {
    @controller('operations.orders.index') index;
    @service intl;
    @service recurringOrderScheduleActions;

    queryParams = [
        { page: { as: 'series_page' } },
        { limit: { as: 'series_limit' } },
        { sort: { as: 'series_sort' } },
        { query: { as: 'series_query' } },
        { status: { as: 'series_status' } },
    ];

    @tracked page = 1;
    @tracked limit = 20;
    @tracked sort = '-created_at';
    @tracked query = null;
    @tracked status = null;

    statusOptions = STATUS_OPTIONS;

    get layout() {
        return this.index?.layout ?? null;
    }

    get selectedStatusOption() {
        return this.statusOptions.find((option) => option.value === this.status) ?? this.statusOptions[0];
    }

    get actionButtons() {
        return [
            {
                icon: 'refresh',
                helpText: this.intl.t('common.refresh'),
                onClick: this.recurringOrderScheduleActions.refresh,
            },
            {
                text: 'New series',
                icon: 'plus',
                type: 'primary',
                onClick: this.recurringOrderScheduleActions.transition.create,
            },
        ];
    }

    get columns() {
        return [
            {
                sticky: true,
                label: 'Series',
                valuePath: 'name',
                cellComponent: 'cell/recurring-series-name',
                route: 'operations.orders.index.series.details',
                onLinkClick: this.recurringOrderScheduleActions.transition.view,
                resizable: true,
                sortable: true,
            },
            {
                label: 'Pattern',
                valuePath: 'rrule',
                cellComponent: 'cell/recurring-series-pattern',
                resizable: true,
            },
            {
                label: 'Next occurrence',
                valuePath: 'next_occurrence_at',
                cellComponent: 'cell/recurring-series-next-occurrence',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.customer'),
                valuePath: 'customer_name',
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
                filterComponent: 'filter/multi-option',
                options: ['active', 'paused', 'canceled'],
            },
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    {
                        label: 'Open series',
                        icon: 'eye',
                        fn: this.recurringOrderScheduleActions.transition.view,
                    },
                    {
                        label: 'Pause',
                        icon: 'pause',
                        fn: this.recurringOrderScheduleActions.pause,
                        isVisible: (series) => series.status !== 'paused' && series.status !== 'canceled',
                    },
                    {
                        label: 'Resume',
                        icon: 'play',
                        fn: this.recurringOrderScheduleActions.resume,
                        isVisible: (series) => series.status === 'paused',
                    },
                    {
                        label: 'Skip next',
                        icon: 'forward-step',
                        fn: this.recurringOrderScheduleActions.skipNextOccurrence,
                        isVisible: (series) => series.status !== 'canceled',
                    },
                    {
                        label: 'Edit template',
                        icon: 'pencil',
                        fn: this.recurringOrderScheduleActions.transition.editTemplate,
                    },
                    {
                        label: 'Cancel future',
                        icon: 'ban',
                        fn: this.recurringOrderScheduleActions.cancelFuture,
                        isVisible: (series) => series.status !== 'canceled',
                    },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
    }

    @task({ restartable: true }) *search(event) {
        this.query = event.target.value || null;
        this.page = 1;
        yield timeout(250);
    }

    @action changeStatus(option) {
        this.status = option?.value ?? null;
        this.page = 1;
    }

    @action openSeries(series) {
        return this.recurringOrderScheduleActions.transition.view(series);
    }
}
