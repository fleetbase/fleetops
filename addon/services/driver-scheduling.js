import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';

export default class DriverSchedulingService extends Service {
    @service store;
    @service fetch;
    @service notifications;
    @tracked currentSchedule = null;
    @tracked scheduleItems = [];
    @tracked constraints = [];

    /**
     * Load a schedule by ID
     */
    @task *loadSchedule(scheduleId) {
        try {
            const schedule = yield this.store.findRecord('schedule', scheduleId, {
                include: 'items',
                reload: true,
            });
            this.currentSchedule = schedule;
            return schedule;
        } catch (error) {
            this.notifications.serverError(error);
            throw error;
        }
    }

    /**
     * Create a new schedule
     */
    @task *createSchedule(data) {
        try {
            const schedule = this.store.createRecord('schedule', data);
            yield schedule.save();
            this.notifications.success('Schedule created successfully');
            return schedule;
        } catch (error) {
            this.notifications.serverError(error);
            throw error;
        }
    }

    /**
     * Create a new schedule item
     */
    @task *createScheduleItem(data) {
        try {
            const item = this.store.createRecord('schedule-item', data);
            yield item.save();
            this.notifications.success('Schedule item created successfully');
            return item;
        } catch (error) {
            this.notifications.serverError(error);
            throw error;
        }
    }

    /**
     * Update a schedule item
     */
    @task *updateScheduleItem(item, data) {
        try {
            item.setProperties(data);
            yield item.save();
            this.notifications.success('Schedule item updated successfully');
            return item;
        } catch (error) {
            this.notifications.serverError(error);
            throw error;
        }
    }

    /**
     * Delete a schedule item
     */
    @task *deleteScheduleItem(item) {
        try {
            yield item.destroyRecord();
            this.notifications.success('Schedule item deleted successfully');
        } catch (error) {
            this.notifications.serverError(error);
            throw error;
        }
    }

    /**
     * Get schedule items for an assignee
     */
    @task *getScheduleItemsForAssignee(assigneeType, assigneeUuid, filters = {}) {
        try {
            const items = yield this.store.query('schedule-item', {
                assignee_type: assigneeType,
                assignee_uuid: assigneeUuid,
                ...filters,
            });
            this.scheduleItems = items.toArray();
            return items;
        } catch (error) {
            this.notifications.serverError(error);
            throw error;
        }
    }

    /**
     * Check availability for a subject
     */
    @task *checkAvailability(subjectType, subjectUuid, startAt, endAt) {
        try {
            const response = yield this.fetch.get('schedule-availability/check', {
                subject_type: subjectType,
                subject_uuid: subjectUuid,
                start_at: startAt,
                end_at: endAt,
            });
            return response.available;
        } catch (error) {
            this.notifications.serverError(error);
            return false;
        }
    }

    /**
     * Set availability for a subject
     */
    @task *setAvailability(data) {
        try {
            const availability = this.store.createRecord('schedule-availability', data);
            yield availability.save();
            this.notifications.success('Availability set successfully');
            return availability;
        } catch (error) {
            this.notifications.serverError(error);
            throw error;
        }
    }

    /**
     * Load constraints for a subject
     */
    @task *loadConstraints(subjectType, subjectUuid) {
        try {
            const constraints = yield this.store.query('schedule-constraint', {
                subject_type: subjectType,
                subject_uuid: subjectUuid,
                is_active: true,
            });
            this.constraints = constraints.toArray();
            return constraints;
        } catch (error) {
            this.notifications.serverError(error);
            throw error;
        }
    }

    /**
     * Validate a schedule item against constraints
     */
    @task *validateScheduleItem(item) {
        try {
            const response = yield this.fetch.post('schedule-items/validate', {
                schedule_item: item.serialize(),
            });
            return response.violations || [];
        } catch (error) {
            this.notifications.serverError(error);
            return [];
        }
    }
}
