import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { isArray } from '@ember/array';
import { startOfWeek, endOfWeek, format } from 'date-fns';

export default class MapDrawerDeviceEventListingComponent extends Component {
    @service store;
    @service hostRouter;
    @service notifications;
    @service deviceEventActions;
    @service deviceActions;
    @service intl;

    @tracked events = [];
    @tracked telematic = null;
    @tracked device = null;
    @tracked dateFilter = [format(startOfWeek(new Date(), { weekStartsOn: 1 }), 'yyyy-MM-dd'), format(endOfWeek(new Date(), { weekStartsOn: 1 }), 'yyyy-MM-dd')];

    get columns() {
        return [
            {
                label: 'Event',
                valuePath: 'event_type',
                cellComponent: 'table/cell/anchor',
                cellClassNames: 'uppercase',
                action: this.deviceEventActions.panel.view,
                permission: 'fleet-ops view device-event',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'name',
                filterComponent: 'filter/string',
            },
            {
                label: 'Device',
                valuePath: 'device.displayName',
                cellComponent: 'table/cell/anchor',
                action: this.deviceActions.panel.view,
                permission: 'fleet-ops view device',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: 'Select device',
                filterParam: 'device',
                model: 'device',
            },
            {
                label: 'Provider',
                valuePath: 'provider',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'provider',
                filterComponent: 'filter/string',
            },
            {
                label: 'Severity',
                valuePath: 'severity',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'severity',
                filterComponent: 'filter/string',
            },
            {
                label: 'IDENT',
                valuePath: 'ident',
                hidden: true,
                resizable: true,
                sortable: true,
            },
            {
                label: 'Protocol',
                valuePath: 'protocol',
                hidden: true,
                resizable: true,
                sortable: true,
            },
            {
                label: 'State',
                valuePath: 'state',
                hidden: true,
                resizable: true,
                sortable: true,
            },
            {
                label: 'Code',
                valuePath: 'code',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'code',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.created-at'),
                valuePath: 'createdAt',
                sortParam: 'created_at',
                width: '10%',
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
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.device-event') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                width: '10%',
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.device-event') }),
                        fn: this.deviceEventActions.panel.view,
                        permission: 'fleet-ops view device-event',
                    },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
    }

    constructor() {
        super(...arguments);
        this.loadEvents.perform();
    }

    @action onDeviceSelected(device) {
        this.device = device;
        this.loadEvents.perform();
    }

    @action onTelematicSelected(telematic) {
        this.telematic = telematic;
        this.loadEvents.perform();
    }

    @action onDateRangeChanged({ formattedDate }) {
        if (isArray(formattedDate) && formattedDate.length === 2) {
            this.dateFilter = formattedDate;
            this.loadEvents.perform();
        }
    }

    @task *loadEvents() {
        try {
            const params = {
                limit: 900,
                sort: 'created_at',
            };

            if (this.telematic) {
                params.telematic = this.telematic.id;
            }

            if (this.device) {
                params.device = this.device.id;
            }

            if (isArray(this.dateFilter) && this.dateFilter.length === 2) {
                params.created_at = this.dateFilter.join(',');
            }

            const events = yield this.store.query('device-event', params);
            this.positions = isArray(events) ? events : [];
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
