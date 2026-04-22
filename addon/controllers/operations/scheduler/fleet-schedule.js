import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, computed } from '@ember/object';
import { task } from 'ember-concurrency';
import { format, startOfWeek, endOfWeek, addWeeks, formatISO } from 'date-fns';
import { Tooltip } from '@fleetbase/ember-ui/utils/floating';

/**
 * OperationsSchedulerFleetScheduleController
 *
 * Fleet-wide driver schedule calendar using FullCalendar `resourceTimelineWeek` view.
 * Each driver is a resource row; materialised ScheduleItem records are rendered as
 * events; approved ScheduleExceptions are shown as red background blocks.
 *
 * Data flow:
 *   1. Route loads active drivers and passes them to this controller
 *   2. loadScheduleItems task fetches materialised shifts for the current window
 *   3. loadScheduleExceptions task fetches approved time-off blocks
 *   4. events / calendarResources computed properties transform data for FullCalendar
 */
export default class OperationsSchedulerFleetScheduleController extends Controller {
    @service modalsManager;
    @service notifications;
    @service store;
    @service fetch;
    @service intl;

    @tracked drivers = [];
    @tracked scheduleItems = [];
    @tracked exceptions = [];
    @tracked calendarApi = null;

    // ── Calendar window ───────────────────────────────────────────────────────

    get windowStart() {
        return formatISO(startOfWeek(new Date(), { weekStartsOn: 1 }));
    }

    get windowEnd() {
        return formatISO(addWeeks(endOfWeek(new Date(), { weekStartsOn: 1 }), 4));
    }

    // ── FullCalendar data ─────────────────────────────────────────────────────

    /**
     * Transform drivers into FullCalendar resource objects.
     */
    @computed('drivers.[]')
    get calendarResources() {
        return this.drivers.map((driver) => ({
            id: driver.id,
            title: driver.name,
            extendedProps: { driver },
        }));
    }

    /**
     * Transform schedule items and approved exceptions into FullCalendar event objects.
     * Approved exceptions are rendered as red background blocks.
     */
    @computed('scheduleItems.[]', 'exceptions.[]', 'drivers.[]')
    get events() {
        const shiftEvents = this.scheduleItems.map((item) => {
            const driver = this.drivers.find((d) => d.id === item.assignee_uuid);
            return {
                id: item.id,
                resourceId: item.assignee_uuid,
                title: item.title || (driver ? `${driver.name} — Shift` : 'Shift'),
                start: item.start_at,
                end: item.end_at,
                backgroundColor: this.getShiftColor(item),
                borderColor: this.getShiftColor(item),
                extendedProps: { scheduleItem: item, driver },
            };
        });

        const exceptionEvents = this.exceptions
            .filter((e) => e.status === 'approved')
            .map((exception) => ({
                id: `exc-${exception.id}`,
                resourceId: exception.subject_uuid,
                start: exception.start_at,
                end: exception.end_at,
                display: 'background',
                backgroundColor: '#FCA5A5', // red-300
                extendedProps: { exception },
            }));

        return [...shiftEvents, ...exceptionEvents];
    }

    /**
     * Map shift status to a color for the calendar event.
     */
    getShiftColor(item) {
        const colors = {
            scheduled: '#6366f1', // indigo
            confirmed: '#22c55e', // green
            in_progress: '#3b82f6', // blue
            completed: '#9ca3af', // gray
            cancelled: '#ef4444', // red
            no_show: '#f97316', // orange
        };
        return colors[item.status] || '#6366f1';
    }

    // ── Data loading ──────────────────────────────────────────────────────────

    /**
     * Load materialised schedule items for all drivers within the calendar window.
     */
    @task *loadScheduleItems() {
        try {
            const items = yield this.store.query('schedule-item', {
                assignee_type: 'fleet-ops:driver',
                start_at_gte: this.windowStart,
                end_at_lte: this.windowEnd,
                limit: 500,
            });
            this.scheduleItems = items.toArray();
            yield this.loadScheduleExceptions.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Load approved ScheduleException records (time-off, sick leave, etc.)
     * within the calendar window to render as background blocks.
     */
    @task *loadScheduleExceptions() {
        try {
            const exceptions = yield this.store.query('schedule-exception', {
                subject_type: 'fleet-ops:driver',
                start_at_gte: this.windowStart,
                end_at_lte: this.windowEnd,
                limit: 200,
            });
            this.exceptions = exceptions.toArray();
        } catch (error) {
            // Non-critical — suppress and continue without exception blocks
            this.exceptions = [];
        }
    }

    // ── Calendar API ──────────────────────────────────────────────────────────

    @action
    setCalendarApi(calendar) {
        this.calendarApi = calendar;

        calendar.setOption('eventDidMount', (info) => {
            if (!info.event.extendedProps.scheduleItem) return;
            const item = info.event.extendedProps.scheduleItem;
            const driver = info.event.extendedProps.driver;
            const tooltip = `${driver?.name || 'Driver'}\n${format(new Date(item.start_at), 'HH:mm')}–${format(new Date(item.end_at), 'HH:mm')}`;
            info.tooltip = new Tooltip(info.el, { text: tooltip });
        });

        calendar.setOption('eventWillUnmount', (info) => {
            info.tooltip?.destroy();
        });
    }

    // ── Event handlers ────────────────────────────────────────────────────────

    /**
     * Handle clicking a shift event — opens the driver-shift edit modal.
     */
    @action
    onEventClick(info) {
        const { scheduleItem } = info.event.extendedProps;
        if (!scheduleItem) return;

        this.modalsManager.show('modals/driver-shift', {
            title: this.intl.t('scheduler.edit-shift'),
            acceptButtonText: this.intl.t('common.save-changes'),
            acceptButtonIcon: 'save',
            item: scheduleItem,
            confirm: async (modal) => {
                modal.startLoading();
                const options = modal.getOptions();
                try {
                    scheduleItem.setProperties({
                        title: options.title,
                        start_at: options.startAt,
                        end_at: options.endAt,
                        notes: options.notes,
                    });
                    await scheduleItem.save();
                    this.notifications.success(this.intl.t('scheduler.shift-updated'));
                    await this.loadScheduleItems.perform();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    /**
     * Handle drag-and-drop rescheduling — supports cross-driver reassignment.
     */
    @action
    async onEventDrop(info) {
        const { scheduleItem } = info.event.extendedProps;
        if (!scheduleItem) {
            info.revert();
            return;
        }

        try {
            scheduleItem.setProperties({
                start_at: info.event.start.toISOString(),
                end_at: info.event.end?.toISOString() || scheduleItem.end_at,
                assignee_uuid: info.newResource?.id || scheduleItem.assignee_uuid, // newResource.id is driver.id (see calendarResources)
            });
            await scheduleItem.save();
            this.notifications.success(this.intl.t('scheduler.shift-updated'));
        } catch (error) {
            info.revert();
            this.notifications.serverError(error);
        }
    }

    // ── Shift management ──────────────────────────────────────────────────────

    /**
     * Open the Add Shift modal, optionally pre-selecting a driver.
     */
    @action
    addShiftForDriver(driver) {
        this.modalsManager.show('modals/add-driver-shift', {
            title: this.intl.t('scheduler.add-shift'),
            acceptButtonText: this.intl.t('scheduler.create-shift'),
            acceptButtonIcon: 'plus',
            drivers: this.drivers,
            selectedDriver: driver,
            confirm: async (modal) => {
                modal.startLoading();
                const options = modal.getOptions();
                const targetDriver = options.selectedDriver || driver;

                try {
                    if (options.isRecurring) {
                        // Build RRULE-based ScheduleTemplate and apply it to the driver's Schedule
                        const template = this.store.createRecord('schedule-template', {
                            name: options.templateName || `${targetDriver.name} Recurring Schedule`,
                            rrule: options.rrule,
                            start_time: options.shiftStartTime,
                            end_time: options.shiftEndTime,
                            break_start_time: options.breakStartTime || null,
                            break_end_time: options.breakEndTime || null,
                            color: options.templateColor || '#6366f1',
                        });
                        const savedTemplate = await template.save();

                        // Find or create the driver's Schedule container
                        const schedules = await this.store.query('schedule', {
                            subject_type: 'fleet-ops:driver',
                            subject_uuid: targetDriver.id,
                            limit: 1,
                        });

                        let schedule;
                        if (schedules.length > 0) {
                            schedule = schedules.firstObject;
                        } else {
                            schedule = await this.store
                                .createRecord('schedule', {
                                    subject_type: 'fleet-ops:driver',
                                    subject_uuid: targetDriver.id,
                                    name: `${targetDriver.name} Schedule`,
                                    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                                    status: 'draft',
                                })
                                .save();
                        }

                        // Apply the template — core-api materialises ScheduleItems
                        await this.fetch.post(`schedule-templates/${savedTemplate.id}/apply`, {
                            subject_type: 'fleet-ops:driver',
                            subject_uuid: targetDriver.id,
                            schedule_uuid: schedule.id,
                            effective_from: options.recurrenceStartDate || new Date().toISOString(),
                            effective_until: options.recurrenceEndDate || null,
                        });

                        this.notifications.success(this.intl.t('scheduler.recurring-schedule-created'));
                    } else {
                        // Single one-off shift
                        const scheduleItem = this.store.createRecord('schedule-item', {
                            assignee_type: 'fleet-ops:driver',
                            assignee_uuid: targetDriver.id,
                            title: options.title || null,
                            start_at: options.startAt,
                            end_at: options.endAt,
                            notes: options.notes || null,
                            status: 'scheduled',
                        });
                        await scheduleItem.save();
                        this.notifications.success(this.intl.t('scheduler.shift-created'));
                    }

                    await this.loadScheduleItems.perform();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    /**
     * Open the Add Shift modal without a pre-selected driver.
     */
    @action
    addShift() {
        this.addShiftForDriver(null);
    }

    // ── Calendar navigation ───────────────────────────────────────────────────

    @action
    previousWeek() {
        this.calendarApi?.prev();
    }

    @action
    nextWeek() {
        this.calendarApi?.next();
    }

    @action
    goToToday() {
        this.calendarApi?.today();
    }
}
