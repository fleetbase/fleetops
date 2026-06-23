import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task, timeout } from 'ember-concurrency';

const severityOptions = [
    { label: 'Info', value: 'info' },
    { label: 'Warning', value: 'warning' },
    { label: 'Error', value: 'error' },
    { label: 'Critical', value: 'critical' },
    { label: 'High', value: 'high' },
];

const warningSeverities = ['warning', 'error', 'critical', 'high'];

export default class ConnectivityDevicesIndexDetailsEventsController extends Controller {
    @service deviceEventActions;
    @service hostRouter;
    @service intl;

    @tracked queryParams = [
        'events_page',
        'events_limit',
        'events_sort',
        'events_query',
        'events_event_type',
        'events_severity',
        'events_processed',
        'events_occurred_at',
        'events_created_at',
    ];
    @tracked device;
    @tracked events_page = 1;
    @tracked events_limit;
    @tracked events_sort = '-created_at';
    @tracked events_query;
    @tracked events_event_type;
    @tracked events_severity;
    @tracked events_processed;
    @tracked events_occurred_at;
    @tracked events_created_at;

    @tracked bulkActions = [];

    get events() {
        return Array.from(this.model ?? []);
    }

    get totalEvents() {
        return this.model?.meta?.total ?? this.events.length;
    }

    get warningEventsCount() {
        return this.events.filter((event) => warningSeverities.includes(String(event.severity ?? '').toLowerCase())).length;
    }

    get unprocessedEventsCount() {
        return this.events.filter((event) => !event.processed_at).length;
    }

    get processedEventsCount() {
        return Math.max(this.events.length - this.unprocessedEventsCount, 0);
    }

    get hasHealthyEventState() {
        return this.events.length > 0 && this.warningEventsCount === 0 && this.unprocessedEventsCount === 0 && !this.hasActiveFilters;
    }

    get hasActiveFilters() {
        return Boolean(this.events_query || this.events_event_type || this.events_severity || this.events_processed || this.events_occurred_at || this.events_created_at);
    }

    get metrics() {
        return [
            { label: 'Recent events', value: this.totalEvents, icon: 'list', accentClass: 'fleetops-connectivity-kpi-accent-blue' },
            {
                label: 'Warnings',
                value: this.warningEventsCount,
                icon: 'triangle-exclamation',
                accentClass: this.warningEventsCount ? 'fleetops-connectivity-kpi-accent-amber' : 'fleetops-connectivity-kpi-accent-green',
            },
            {
                label: 'Unprocessed',
                value: this.unprocessedEventsCount,
                icon: 'clock',
                accentClass: this.unprocessedEventsCount ? 'fleetops-connectivity-kpi-accent-rose' : 'fleetops-connectivity-kpi-accent-green',
            },
            { label: 'Processed', value: this.processedEventsCount, icon: 'check', accentClass: 'fleetops-connectivity-kpi-accent-green' },
        ];
    }

    get actionButtons() {
        return [
            {
                icon: 'refresh',
                size: 'sm',
                onClick: this.refresh,
                helpText: this.intl.t('common.refresh'),
                wrapperClass: 'fleetops-telematics-action-button',
                isLoading: this.refreshTask.isRunning,
                disabled: this.refreshTask.isRunning,
            },
        ];
    }

    get columns() {
        return [
            {
                sticky: true,
                label: 'Event',
                valuePath: 'event_type',
                cellComponent: 'table/cell/anchor',
                action: this.deviceEventActions.transition.view,
                permission: 'fleet-ops view device-event',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'events_event_type',
                filterComponent: 'filter/string',
            },
            {
                label: 'Severity',
                valuePath: 'severity',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'events_severity',
                filterComponent: 'filter/multi-option',
                filterOptions: severityOptions,
                filterOptionLabel: 'label',
                filterOptionValue: 'value',
            },
            {
                label: 'Message',
                valuePath: 'message',
                resizable: true,
                sortable: false,
            },
            {
                label: 'Code',
                valuePath: 'code',
                resizable: true,
                sortable: true,
            },
            {
                label: 'Processed',
                valuePath: 'processedAt',
                sortParam: 'processed_at',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'events_processed',
                filterComponent: 'filter/multi-option',
                filterOptions: [
                    { label: 'Processed', value: 'processed' },
                    { label: 'Unprocessed', value: 'unprocessed' },
                ],
                filterOptionLabel: 'label',
                filterOptionValue: 'value',
            },
            {
                label: 'Occurred',
                valuePath: 'occurredAt',
                sortParam: 'occurred_at',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'events_occurred_at',
                filterComponent: 'filter/date',
            },
            {
                label: this.intl.t('column.created-at'),
                valuePath: 'createdAt',
                sortParam: 'created_at',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'events_created_at',
                filterComponent: 'filter/date',
                hidden: true,
            },
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.device-event') }),
                cellClassNames: 'overflow-visible align-middle',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.device-event') }),
                        fn: this.deviceEventActions.transition.view,
                        permission: 'fleet-ops view device-event',
                    },
                    {
                        label: 'Mark processed',
                        fn: this.markProcessed,
                        permission: 'fleet-ops update device-event',
                    },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
    }

    @action refresh() {
        if (this.refreshTask.isRunning) {
            return;
        }

        return this.refreshTask.perform();
    }

    @action async markProcessed(deviceEvent) {
        await this.deviceEventActions.markProcessed(deviceEvent);
        await this.hostRouter.refresh();
    }

    @task *refreshTask() {
        yield this.hostRouter.refresh();
    }

    @task({ restartable: true }) *searchTask(event) {
        const {
            target: { value },
        } = event;

        if (!value) {
            this.events_query = null;
            return;
        }

        yield timeout(250);
        if (this.events_page > 1) this.events_page = 1;
        this.events_query = value;
    }
}
