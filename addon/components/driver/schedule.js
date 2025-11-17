import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * Driver::Schedule Component
 *
 * Displays and manages a driver's schedule from their detail page.
 * Includes HOS compliance tracking, upcoming shifts, and availability management.
 *
 * @example
 * <Driver::Schedule @resource={{@model}} />
 */
export default class DriverScheduleComponent extends Component {
    @service driverScheduling;
    @service notifications;
    @service modalsManager;
    @service store;
    @tracked scheduleItems = [];
    @tracked upcomingShifts = [];
    @tracked hosStatus = null;
    @tracked availability = [];
    @tracked timeOffRequests = [];
    @tracked selectedItem = null;

    get scheduleActionButtons() {
        return [
            {
                type: 'default',
                text: 'Request Time Off',
                icon: 'calendar-times',
                iconPrefix: 'fas',
                permission: 'fleet-ops update driver',
                onClick: this.requestTimeOff,
            },
        ];
    }

    get shiftActionButtons() {
        return [
            {
                type: 'default',
                text: 'Add Shift',
                icon: 'plus',
                iconPrefix: 'fas',
                permission: 'fleet-ops update driver',
                onClick: this.addShift,
            },
        ];
    }

    get availabilityActionButtons() {
        return [
            {
                type: 'default',
                text: 'Set Availability',
                icon: 'clock',
                iconPrefix: 'fas',
                permission: 'fleet-ops update driver',
                onClick: this.setAvailability,
            },
        ];
    }

    constructor() {
        super(...arguments);
        this.loadDriverSchedule.perform();
        this.loadHOSStatus.perform();
        this.loadAvailability.perform();
    }

    /**
     * Load driver schedule items
     */
    @task *loadDriverSchedule() {
        try {
            const items = yield this.driverScheduling.getScheduleItemsForAssignee.perform('driver', this.args.resource.id, {
                start_at: this.startDate,
                end_at: this.endDate,
            });

            this.scheduleItems = items.toArray();
            this.upcomingShifts = this.scheduleItems.filter((item) => new Date(item.start_at) > new Date()).slice(0, 5);
        } catch (error) {
            console.error('Failed to load driver schedule:', error);
        }
    }

    /**
     * Load HOS status for the driver
     */
    @task *loadHOSStatus() {
        try {
            const response = yield this.fetch.get(`drivers/${this.args.resource.id}/hos-status`);
            this.hosStatus = response;
        } catch (error) {
            console.error('Failed to load HOS status:', error);
        }
    }

    /**
     * Load driver availability
     */
    @task *loadAvailability() {
        try {
            const availability = yield this.store.query('schedule-availability', {
                subject_type: 'driver',
                subject_uuid: this.args.resource.id,
                start_at: this.startDate,
                end_at: this.endDate,
            });

            this.availability = availability.toArray();
        } catch (error) {
            console.error('Failed to load availability:', error);
        }
    }

    /**
     * Get start date for schedule query (current week)
     */
    get startDate() {
        const now = new Date();
        const dayOfWeek = now.getDay();
        const diff = now.getDate() - dayOfWeek;
        return new Date(now.setDate(diff)).toISOString();
    }

    /**
     * Get end date for schedule query (4 weeks out)
     */
    get endDate() {
        const now = new Date();
        return new Date(now.setDate(now.getDate() + 28)).toISOString();
    }

    /**
     * Get HOS compliance badge color
     */
    get hosComplianceBadge() {
        if (!this.hosStatus) {
            return { color: 'gray', text: 'Unknown' };
        }

        const { daily_hours, weekly_hours } = this.hosStatus;

        if (daily_hours >= 11 || weekly_hours >= 70) {
            return { color: 'red', text: 'At Limit' };
        }

        if (daily_hours >= 9 || weekly_hours >= 60) {
            return { color: 'yellow', text: 'Approaching Limit' };
        }

        return { color: 'green', text: 'Compliant' };
    }

    /**
     * Handle item click
     */
    @action
    handleItemClick(item) {
        this.selectedItem = item;
        this.modalsManager.show('modals/schedule-item-details', {
            item,
            onEdit: this.editScheduleItem,
            onDelete: this.deleteScheduleItem,
        });
    }

    /**
     * Add new shift
     */
    @action
    addShift() {
        this.modalsManager.show('modals/add-shift', {
            driver: this.args.resource,
            onSave: this.handleShiftAdded,
        });
    }

    /**
     * Edit schedule item
     */
    @action
    async editScheduleItem(item) {
        this.modalsManager.show('modals/edit-shift', {
            item,
            driver: this.args.resource,
            onSave: this.handleShiftUpdated,
        });
    }

    /**
     * Delete schedule item
     */
    @action
    async deleteScheduleItem(item) {
        if (confirm('Are you sure you want to delete this shift?')) {
            try {
                await this.driverScheduling.deleteScheduleItem.perform(item);
                await this.loadDriverSchedule.perform();
            } catch (error) {
                console.error('Failed to delete shift:', error);
            }
        }
    }

    /**
     * Handle shift added
     */
    @action
    async handleShiftAdded() {
        await this.loadDriverSchedule.perform();
        await this.loadHOSStatus.perform();
    }

    /**
     * Handle shift updated
     */
    @action
    async handleShiftUpdated() {
        await this.loadDriverSchedule.perform();
        await this.loadHOSStatus.perform();
    }

    /**
     * Request time off
     */
    @action
    requestTimeOff() {
        this.modalsManager.show('modals/request-time-off', {
            driver: this.args.resource,
            onSave: this.handleTimeOffRequested,
        });
    }

    /**
     * Handle time off requested
     */
    @action
    async handleTimeOffRequested() {
        await this.loadAvailability.perform();
        await this.loadDriverSchedule.perform();
    }

    /**
     * Set availability
     */
    @action
    setAvailability() {
        this.modalsManager.show('modals/set-availability', {
            driver: this.args.resource,
            onSave: this.handleAvailabilitySet,
        });
    }

    /**
     * Handle availability set
     */
    @action
    async handleAvailabilitySet() {
        await this.loadAvailability.perform();
    }
}
