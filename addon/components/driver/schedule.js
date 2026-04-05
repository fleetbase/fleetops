import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { startOfWeek, endOfWeek, addWeeks, formatISO } from 'date-fns';

/**
 * Driver::Schedule Component
 *
 * Displays and manages a driver's schedule from their detail panel.
 * Renders upcoming shifts in a list view and provides actions to add shifts,
 * request time off, and manage availability windows.
 *
 * Wires directly to the core-api scheduling system via the driverScheduling
 * service. The schedule_items relationship on the Driver model uses
 * assignee_type='driver' and assignee_uuid=driver.id as the polymorphic key.
 *
 * Loading state is derived directly from ember-concurrency task instances
 * (e.g. this.loadDriverSchedule.isRunning) — no redundant @tracked booleans.
 *
 * @example
 * <Driver::Schedule @resource={{@driver}} />
 */
export default class DriverScheduleComponent extends Component {
    @service driverScheduling;
    @service notifications;
    @service modalsManager;
    @service store;
    @service fetch;
    @service intl;

    @tracked scheduleItems = [];
    @tracked upcomingShifts = [];
    @tracked availability = [];
    @tracked hosStatus = null;

    constructor() {
        super(...arguments);
        this.loadDriverSchedule.perform();
        this.loadAvailability.perform();
        this.loadHOSStatus.perform();
    }

    /**
     * Start of the current week — used as the lower bound for schedule queries.
     */
    get startDate() {
        return formatISO(startOfWeek(new Date(), { weekStartsOn: 1 }));
    }

    /**
     * Four weeks from now — used as the upper bound for schedule queries.
     */
    get endDate() {
        return formatISO(addWeeks(endOfWeek(new Date(), { weekStartsOn: 1 }), 4));
    }

    /**
     * Derive a color-coded HOS compliance badge from the loaded hosStatus.
     */
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

    /**
     * Action buttons rendered in the Upcoming Shifts ContentPanel header.
     */
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

    /**
     * Action buttons rendered in the Availability ContentPanel header.
     */
    get availabilityActionButtons() {
        return [
            {
                type: 'default',
                text: this.intl.t('scheduler.set-availability'),
                icon: 'clock',
                iconPrefix: 'fas',
                permission: 'fleet-ops update driver',
                onClick: this.setAvailability,
            },
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

    /**
     * Load all schedule items (shifts) for this driver within the 4-week window.
     * Uses the driverScheduling service which calls the core-api schedule-items endpoint
     * with assignee_type=driver and assignee_uuid=driver.id.
     *
     * Loading state is available as this.loadDriverSchedule.isRunning in templates.
     */
    @task *loadDriverSchedule() {
        try {
            const items = yield this.driverScheduling.getScheduleItemsForAssignee.perform('driver', this.args.resource.id, {
                start_at: this.startDate,
                end_at: this.endDate,
            });
            this.scheduleItems = items.toArray();
            this.upcomingShifts = this.scheduleItems
                .filter((item) => new Date(item.start_at) >= new Date())
                .sort((a, b) => new Date(a.start_at) - new Date(b.start_at));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Load availability records (time-off, preferred hours) for this driver.
     * Uses the core-api schedule-availability endpoint with subject_type=driver.
     *
     * Loading state is available as this.loadAvailability.isRunning in templates.
     */
    @task *loadAvailability() {
        try {
            const availability = yield this.store.query('schedule-availability', {
                subject_type: 'driver',
                subject_uuid: this.args.resource.id,
            });
            this.availability = availability.toArray();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Load HOS (Hours of Service) status from the FleetOps driver endpoint.
     * Best-effort — if the endpoint is not yet implemented hosStatus stays null
     * and the HOS panel is hidden by the template's {{#if this.hosStatus}} guard.
     */
    @task *loadHOSStatus() {
        try {
            const response = yield this.fetch.get(`fleet-ops/drivers/${this.args.resource.id}/hos-status`);
            this.hosStatus = response;
        } catch {
            // HOS endpoint not yet implemented — suppress error silently
            this.hosStatus = null;
        }
    }

    /**
     * Open the Add Shift modal. Uses the same modalsManager pattern as the
     * global scheduler's addDriverShift() action, reusing modals/add-driver-shift.
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
                const { startAt, endAt, duration } = modal.getOptions();
                try {
                    const scheduleItem = this.store.createRecord('schedule-item', {
                        assignee_type: 'driver',
                        assignee_uuid: this.args.resource.id,
                        start_at: startAt,
                        end_at: endAt,
                        duration: duration,
                        status: 'pending',
                    });
                    await scheduleItem.save();
                    this.notifications.success(this.intl.t('scheduler.shift-created'));
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
     * Open the Edit Shift modal for an existing schedule item.
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
                const { startAt, endAt } = modal.getOptions();
                try {
                    item.setProperties({ start_at: startAt, end_at: endAt });
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
     * Delete a shift after inline confirmation via modalsManager.
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
                    await this.driverScheduling.deleteScheduleItem.perform(item);
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
     * Open the Set Availability modal to record preferred working hours.
     */
    @action
    setAvailability() {
        this.modalsManager.show('modals/set-driver-availability', {
            title: this.intl.t('scheduler.set-availability'),
            acceptButtonText: this.intl.t('common.save'),
            acceptButtonIcon: 'check',
            driver: this.args.resource,
            isAvailable: true,
            confirm: async (modal) => {
                modal.startLoading();
                const { startAt, endAt, isAvailable, reason, notes } = modal.getOptions();
                try {
                    const availability = this.store.createRecord('schedule-availability', {
                        subject_type: 'driver',
                        subject_uuid: this.args.resource.id,
                        start_at: startAt,
                        end_at: endAt,
                        is_available: isAvailable,
                        reason,
                        notes,
                    });
                    await availability.save();
                    this.notifications.success(this.intl.t('scheduler.availability-set'));
                    await this.loadAvailability.perform();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    /**
     * Open the Request Time Off modal. Creates a schedule-availability record
     * with is_available=false to mark the driver as unavailable.
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
                const { startAt, endAt, reason, notes } = modal.getOptions();
                try {
                    const availability = this.store.createRecord('schedule-availability', {
                        subject_type: 'driver',
                        subject_uuid: this.args.resource.id,
                        start_at: startAt,
                        end_at: endAt,
                        is_available: false,
                        reason,
                        notes,
                    });
                    await availability.save();
                    this.notifications.success(this.intl.t('scheduler.time-off-requested'));
                    await this.loadAvailability.perform();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    /**
     * Delete an availability record.
     */
    @action
    deleteAvailability(avail) {
        this.modalsManager.confirm({
            title: this.intl.t('scheduler.delete-availability'),
            body: this.intl.t('scheduler.delete-availability-confirm'),
            acceptButtonText: this.intl.t('common.delete'),
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await avail.destroyRecord();
                    this.notifications.success(this.intl.t('scheduler.availability-deleted'));
                    await this.loadAvailability.perform();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }
}
