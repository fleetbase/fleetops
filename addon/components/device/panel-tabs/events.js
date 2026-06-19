import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';

const severityOptions = [
    { label: 'Info', value: 'info' },
    { label: 'Warning', value: 'warning' },
    { label: 'Error', value: 'error' },
    { label: 'Critical', value: 'critical' },
    { label: 'High', value: 'high' },
];

function toRecentList(records) {
    const list = Array.from(records ?? []);

    list.meta = {
        current_page: 1,
        last_page: 1,
        per_page: list.length,
        total: list.length,
        from: list.length > 0 ? 1 : 0,
        to: list.length,
    };

    return list;
}

export default class DevicePanelTabsEventsComponent extends Component {
    @service deviceEventActions;
    @service store;

    @tracked events = toRecentList();

    constructor() {
        super(...arguments);
        this.loadEvents.perform();
    }

    get device() {
        return this.args.resource ?? this.args.model;
    }

    get columns() {
        return [
            {
                sticky: true,
                label: 'Event',
                valuePath: 'event_type',
                cellComponent: 'table/cell/anchor',
                action: this.deviceEventActions.panel?.view ?? this.deviceEventActions.transition.view,
                permission: 'fleet-ops view device-event',
                resizable: true,
            },
            {
                label: 'Severity',
                valuePath: 'severity',
                cellComponent: 'table/cell/status',
                filterOptions: severityOptions,
                resizable: true,
            },
            {
                label: 'Message',
                valuePath: 'message',
                resizable: true,
            },
            {
                label: 'Code',
                valuePath: 'code',
                resizable: true,
            },
            {
                label: 'Processed',
                valuePath: 'processedAt',
                sortParam: 'processed_at',
                resizable: true,
            },
            {
                label: 'Occurred',
                valuePath: 'occurredAt',
                sortParam: 'occurred_at',
                resizable: true,
            },
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    {
                        label: 'View event',
                        fn: this.deviceEventActions.panel?.view ?? this.deviceEventActions.transition.view,
                        permission: 'fleet-ops view device-event',
                    },
                    {
                        label: 'Mark processed',
                        fn: this.markProcessed,
                        permission: 'fleet-ops update device-event',
                    },
                ],
            },
        ];
    }

    @action async markProcessed(deviceEvent) {
        await this.deviceEventActions.markProcessed(deviceEvent);
        await this.loadEvents.perform();
    }

    @action refreshEvents() {
        return this.loadEvents.perform();
    }

    @task *loadEvents() {
        if (!this.device?.id) {
            this.events = toRecentList();
            return;
        }

        const events = yield this.store.query('device-event', { device_uuid: this.device.id, limit: 10, sort: '-created_at' });
        this.events = toRecentList(events);
    }
}
