import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { startOfWeek, endOfWeek, addWeeks, subWeeks, formatISO, addDays, format, isSameDay, startOfDay } from 'date-fns';

/**
 * Driver::Schedule Component
 *
 * Displays and manages a driver's schedule from their detail panel.
 *
 * Architecture:
 *   - A Driver has one or more Schedule records (containers, subject_type='driver')
 *   - Each Schedule is materialised into ScheduleItem records by the daily
 *     MaterializeSchedulesJob (RRULE → concrete shifts for 60-day window)
 *   - Deviations are tracked as ScheduleException records (time off, sick leave, etc.)
 *
 * This component:
 *   1. Loads the driver's primary Schedule (or creates one on first use)
 *   2. Loads materialised ScheduleItem records for the next 4 weeks
 *   3. Loads pending/approved ScheduleException records
 *   4. Provides actions to add shifts (single or recurring), request time off, and
 *      manage exceptions
 *
 * @example
 *   <Driver::Schedule @resource={{@driver}} />
 */
export default class DriverScheduleComponent extends Component {
    @service notifications;
    @service modalsManager;
    @service store;
    @service fetch;
    @service intl;

    @tracked schedule = null;
    @tracked scheduleItems = [];
    @tracked upcomingShifts = [];
    @tracked exceptions = [];
    @tracked hosStatus = null;
    /** Anchor date for the 7-day calendar view — start of the displayed week */
    @tracked calendarAnchor = startOfDay(new Date());

    constructor() {
        super(...arguments);
        this.loadDriverSchedule.perform();
        this.loadHOSStatus.perform();
    }

    // ── Date window helpers ───────────────────────────────────────────────────

    get startDate() {
        return formatISO(startOfWeek(new Date(), { weekStartsOn: 1 }));
    }

    get endDate() {
        return formatISO(addWeeks(endOfWeek(new Date(), { weekStartsOn: 1 }), 4));
    }

    get weekRangeLabel() {
        const start = this.calendarAnchor;
        const end = addDays(start, 6);
        if (format(start, 'MMM') === format(end, 'MMM')) {
            return `${format(start, 'MMM d')} – ${format(end, 'd, yyyy')}`;
        }
        return `${format(start, 'MMM d')} – ${format(end, 'MMM d, yyyy')}`;
    }

    @action prevWeek() {
        this.calendarAnchor = subWeeks(this.calendarAnchor, 1);
    }

    @action nextWeek() {
        this.calendarAnchor = addWeeks(this.calendarAnchor, 1);
    }

    // ── Derived state ─────────────────────────────────────────────────────────

    get hosComplianceBadge() {
        if (!this.hosStatus) {
            return { color: 'gray', label: 'Unknown' };
        }
        const { daily_hours, weekly_hours } = this.hosStatus;
        if (daily_hours >= 11 || weekly_hours >= 70) {
            return { color: 'red', label: 'At Limit' };
        }
        if (daily_hours >= 9 || weekly_hours >= 60) {
            return { color: 'yellow', label: 'Approaching Limit' };
        }
        return { color: 'green', label: 'Compliant' };
    }

    get pendingExceptions() {
        return this.exceptions.filter((e) => e.status === 'pending');
    }

    get approvedExceptions() {
        return this.exceptions.filter((e) => e.status === 'approved');
    }

    /**
     * Returns the Tailwind CSS classes for a shift status badge.
     * Called directly from the template as {{this.shiftStatusClass status}}.
     */
    shiftStatusClass(status) {
        const map = {
            scheduled: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200',
            in_progress: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
            completed: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400',
            cancelled: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        };
        return map[status] ?? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
    }

    /**
     * Returns 7 days starting from calendarAnchor with any shifts that fall
     * on each day. Used to render the compact 7-day calendar grid.
     * Navigate with prevWeek/nextWeek actions.
     */
    get weekDays() {
        const anchor = this.calendarAnchor;
        const today = startOfDay(new Date());
        return Array.from({ length: 7 }, (_, i) => {
            const day = addDays(anchor, i);
            // Match items whose start_at falls on this calendar day (local time)
            const shifts = this.scheduleItems.filter((item) => {
                const itemDate = item.start_at instanceof Date ? item.start_at : new Date(item.start_at);
                return isSameDay(itemDate, day);
            });
            return {
                date: day,
                label: format(day, 'EEE'),
                dayNum: format(day, 'd'),
                isToday: isSameDay(day, today),
                shifts,
            };
        });
    }

    // ── Panel action buttons ──────────────────────────────────────────────────

    get shiftActionButtons() {
        return [
            {
                type: 'default',
                text: this.intl.t('scheduler.add-shift'),
                icon: 'plus',
                iconPrefix: 'fas',
                permission: 'fleet-ops update driver',
                onClick: this.addShift,
            },
        ];
    }

    get exceptionActionButtons() {
        return [
            {
                type: 'default',
                text: this.intl.t('scheduler.request-time-off'),
                icon: 'calendar-times',
                iconPrefix: 'fas',
                permission: 'fleet-ops update driver',
                onClick: this.requestTimeOff,
            },
        ];
    }

    // ── Data loading ──────────────────────────────────────────────────────────

    /**
     * Load the driver's primary Schedule container, then load its materialised
     * ScheduleItems and ScheduleExceptions.
     *
     * If no Schedule exists yet for this driver, we create a draft one so the
     * UI is always ready to accept shifts.
     */
    @task *loadDriverSchedule() {
        try {
            // 1. Find or create the driver's primary schedule
            const schedules = yield this.store.query('schedule', {
                subject_type: 'fleet-ops:driver',
                subject_uuid: this.args.resource.id,
                limit: 1,
            });

            if (schedules.length > 0) {
                this.schedule = schedules.firstObject;
            } else {
                const newSchedule = this.store.createRecord('schedule', {
                    subject_type: 'fleet-ops:driver',
                    subject_uuid: this.args.resource.id,
                    name: `${this.args.resource.name} Schedule`,
                    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                    status: 'draft',
                });
                this.schedule = yield newSchedule.save();
            }

            // 2. Load materialised schedule items within the 4-week window
            // Use _gte/_lte operator suffixes so the API applies range filtering
            // (plain start_at/end_at would be treated as exact equality by the query builder)
            const items = yield this.store.query('schedule-item', {
                schedule_uuid: this.schedule.id,
                start_at_gte: this.startDate,
                end_at_lte: this.endDate,
            });

            this.scheduleItems = items.toArray();
            this.upcomingShifts = this.scheduleItems.filter((item) => new Date(item.start_at) >= new Date()).sort((a, b) => new Date(a.start_at) - new Date(b.start_at));

            // 3. Load schedule exceptions
            const exceptions = yield this.store.query('schedule-exception', {
                schedule_uuid: this.schedule.id,
            });
            this.exceptions = exceptions.toArray();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Load HOS (Hours of Service) status from the FleetOps driver endpoint.
     * Best-effort — if the endpoint is not yet implemented hosStatus stays null.
     */
    @task *loadHOSStatus() {
        try {
            const response = yield this.fetch.get(`drivers/${this.args.resource.id}/hos-status`);
            this.hosStatus = response;
        } catch {
            this.hosStatus = null;
        }
    }

    // ── Shift management ──────────────────────────────────────────────────────

    /**
     * Open the Add Shift modal.
     *
     * Two modes are supported:
     *   - Single shift: creates a ScheduleItem directly on the driver's Schedule
     *   - Recurring schedule: creates a ScheduleTemplate with an RRULE and
     *     triggers materialisation via the core-api schedule-templates/{id}/apply endpoint
     */
    @action
    addShift() {
        this.modalsManager.show('modals/add-driver-shift', {
            title: this.intl.t('scheduler.add-shift'),
            acceptButtonText: this.intl.t('scheduler.create-shift'),
            acceptButtonIcon: 'plus',
            drivers: [this.args.resource],
            selectedDriver: this.args.resource,
            confirm: async (modal) => {
                modal.startLoading();
                const options = modal.getOptions();

                try {
                    if (options.isRecurring) {
                        // Recurring mode: create a ScheduleTemplate then apply it
                        const template = this.store.createRecord('schedule-template', {
                            name: options.templateName || `${this.args.resource.name} Recurring Schedule`,
                            rrule: options.rrule,
                            start_time: options.shiftStartTime,
                            end_time: options.shiftEndTime,
                            break_start_time: options.breakStartTime || null,
                            break_end_time: options.breakEndTime || null,
                            color: options.templateColor || '#6366f1',
                        });
                        const savedTemplate = await template.save();

                        await this.fetch.post(`schedule-templates/${savedTemplate.id}/apply`, {
                            subject_type: 'fleet-ops:driver',
                            subject_uuid: this.args.resource.id,
                            schedule_uuid: this.schedule.id,
                            effective_from: options.recurrenceStartDate || new Date().toISOString(),
                            effective_until: options.recurrenceEndDate || null,
                        });

                        this.notifications.success(this.intl.t('scheduler.recurring-schedule-created'));
                    } else {
                        // Single shift mode: create a ScheduleItem directly
                        const scheduleItem = this.store.createRecord('schedule-item', {
                            schedule_uuid: this.schedule.id,
                            assignee_type: 'fleet-ops:driver',
                            assignee_uuid: this.args.resource.id,
                            title: options.title || null,
                            start_at: options.startAt,
                            end_at: options.endAt,
                            notes: options.notes || null,
                            status: 'scheduled',
                        });
                        await scheduleItem.save();
                        this.notifications.success(this.intl.t('scheduler.shift-created'));
                    }

                    await this.loadDriverSchedule.perform();
                    await this.loadHOSStatus.perform();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    /**
     * Open the Edit Shift modal for an existing ScheduleItem.
     */
    @action
    editShift(item) {
        this.modalsManager.show('modals/driver-shift', {
            title: this.intl.t('scheduler.edit-shift'),
            acceptButtonText: this.intl.t('common.save-changes'),
            acceptButtonIcon: 'save',
            item,
            confirm: async (modal) => {
                modal.startLoading();
                const options = modal.getOptions();
                try {
                    item.setProperties({
                        title: options.title,
                        start_at: options.startAt,
                        end_at: options.endAt,
                        notes: options.notes,
                    });
                    await item.save();
                    this.notifications.success(this.intl.t('scheduler.shift-updated'));
                    await this.loadDriverSchedule.perform();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    /**
     * Delete a shift after inline confirmation.
     */
    @action
    deleteShift(item) {
        this.modalsManager.confirm({
            title: this.intl.t('scheduler.delete-shift'),
            body: this.intl.t('scheduler.delete-shift-confirm'),
            acceptButtonText: this.intl.t('common.delete'),
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await item.destroyRecord();
                    this.notifications.success(this.intl.t('scheduler.shift-deleted'));
                    await this.loadDriverSchedule.perform();
                    await this.loadHOSStatus.perform();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    // ── Exception management ──────────────────────────────────────────────────

    /**
     * Open the Request Time Off modal.
     * Creates a ScheduleException with type='time_off' and status='pending'.
     */
    @action
    requestTimeOff() {
        this.modalsManager.show('modals/set-driver-availability', {
            title: this.intl.t('scheduler.request-time-off'),
            acceptButtonText: this.intl.t('scheduler.submit-request'),
            acceptButtonIcon: 'calendar-times',
            driver: this.args.resource,
            isAvailable: false,
            confirm: async (modal) => {
                modal.startLoading();
                const options = modal.getOptions();
                try {
                    const exception = this.store.createRecord('schedule-exception', {
                        schedule_uuid: this.schedule?.id,
                        subject_type: 'fleet-ops:driver',
                        subject_uuid: this.args.resource.id,
                        type: 'time_off',
                        status: 'pending',
                        start_at: options.startAt,
                        end_at: options.endAt,
                        reason: options.reason || null,
                        notes: options.notes || null,
                    });
                    await exception.save();
                    this.notifications.success(this.intl.t('scheduler.time-off-requested'));
                    await this.loadDriverSchedule.perform();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    /**
     * Approve a pending ScheduleException (manager action).
     */
    @action
    async approveException(exception) {
        try {
            await this.fetch.post(`schedule-exceptions/${exception.id}/approve`);
            exception.set('status', 'approved');
            this.notifications.success(this.intl.t('scheduler.exception-approved'));
            await this.loadDriverSchedule.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Reject a pending ScheduleException (manager action).
     */
    @action
    async rejectException(exception) {
        try {
            await this.fetch.post(`schedule-exceptions/${exception.id}/reject`);
            exception.set('status', 'rejected');
            this.notifications.success(this.intl.t('scheduler.exception-rejected'));
            await this.loadDriverSchedule.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Delete a ScheduleException record.
     */
    @action
    deleteException(exception) {
        this.modalsManager.confirm({
            title: this.intl.t('scheduler.delete-exception'),
            body: this.intl.t('scheduler.delete-exception-confirm'),
            acceptButtonText: this.intl.t('common.delete'),
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await exception.destroyRecord();
                    this.notifications.success(this.intl.t('scheduler.exception-deleted'));
                    await this.loadDriverSchedule.perform();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }
}
