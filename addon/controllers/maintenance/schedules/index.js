import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

const CACHE_KEY = 'fleetops:maintenance-schedules:layout';

/** Map schedule status to a FullCalendar event background colour. */
function statusColor(status) {
    switch (status) {
        case 'overdue':
            return '#EF4444'; // red-500
        case 'paused':
            return '#9CA3AF'; // gray-400
        case 'active':
        default:
            return '#F59E0B'; // amber-500
    }
}

export default class MaintenanceSchedulesIndexController extends Controller {
    @service maintenanceScheduleActions;
    @service fetch;
    @service intl;
    @service appCache;
    @service notifications;

    /** Alias used by the tabular layout template (@onSearch={{perform this.scheduleActions.controllerSearchTask this}}) */
    get scheduleActions() {
        return this.maintenanceScheduleActions;
    }

    @tracked queryParams = ['status', 'page', 'limit', 'sort', 'query', 'public_id', 'created_at', 'updated_at'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked status;

    /** 'list' | 'calendar' — persisted via appCache (same pattern as vehicles layout) */
    @tracked layout = this.appCache.get(CACHE_KEY, 'list');

    /** FullCalendar API instance (set via @onInit) */
    calendarApi = null;

    // ─── View mode ────────────────────────────────────────────────────────────

    @action setCalendarApi(api) {
        this.calendarApi = api;
    }

    // ─── Calendar feed (function-based event source for FullCalendar v6) ─────
    //
    // NOTE: layout is toggled via the dropdown-button in actionButtons (same
    // pattern as the vehicles index layout switcher).
    //
    // FullCalendar v6 supports passing a function as the `events` option:
    //   events: function(fetchInfo, successCallback, failureCallback)
    // The function is called automatically whenever the calendar needs to
    // (re-)fetch events — on initial render and on navigation. `fetchInfo`
    // contains `startStr` and `endStr` (ISO date strings) for the visible range.
    //
    // We bind this method and pass it as @events to the <FullCalendar> component.
    // The component passes it straight through to `new Calendar(el, { events })`.

    get calendarEventSource() {
        // Return a bound function so `this` is always the controller.
        // FullCalendar v6 calls this function with (fetchInfo, successCallback,
        // failureCallback) whenever it needs to (re-)fetch events.
        return (fetchInfo, successCallback, failureCallback) => {
            this.fetch
                .get('maintenance-schedules/calendar-feed', {
                    start: fetchInfo.startStr,
                    end: fetchInfo.endStr,
                })
                .then((response) => {
                    // The API returns { events: [...] } — unwrap the array.
                    const raw = Array.isArray(response) ? response : (response?.events ?? []);
                    const events = raw.map((event) => ({
                        id: event.id,
                        title: event.title,
                        start: event.start,
                        end: event.end,
                        allDay: event.allDay !== false,
                        // Use the colour the backend already computed; fall back
                        // to the local statusColor helper if absent.
                        backgroundColor: event.color ?? statusColor(event.status),
                        borderColor: event.color ?? statusColor(event.status),
                        extendedProps: {
                            public_id: event.id,
                            uuid: event.uuid,
                            subject_name: event.subject_name,
                            assignee_name: event.assignee_name,
                            status: event.status,
                            priority: event.priority,
                            type: event.type,
                        },
                    }));
                    successCallback(events);
                })
                .catch((error) => {
                    failureCallback(error);
                });
        };
    }

    // ─── Calendar event click ─────────────────────────────────────────────────

    @action onCalendarEventClick({ event }) {
        const publicId = event?.extendedProps?.public_id;
        if (publicId) {
            // transition.view expects an object whose public_id is used as the URL segment
            this.maintenanceScheduleActions.transition.view(publicId);
        }
    }

    // ─── Action buttons ───────────────────────────────────────────────────────

    @action setLayoutList() {
        this.layout = 'list';
        this.appCache.set(CACHE_KEY, 'list');
    }

    @action setLayoutCalendar() {
        this.layout = 'calendar';
        this.appCache.set(CACHE_KEY, 'calendar');
    }

    get actionButtons() {
        return [
            {
                component: 'dropdown-button',
                icon: 'display',
                size: 'xs',
                items: [
                    {
                        label: this.intl.t('common.table-view'),
                        icon: 'table-list',
                        onClick: this.setLayoutList,
                    },
                    {
                        label: this.intl.t('common.calendar-view'),
                        icon: 'calendar',
                        onClick: this.setLayoutCalendar,
                    },
                ],
                renderInPlace: true,
                helpText: this.intl.t('common.change-layout'),
            },
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
