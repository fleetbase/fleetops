import Model, { attr, belongsTo } from '@ember-data/model';

export default class MaintenanceScheduleModel extends Model {
    // Identification
    @attr('string') public_id;
    @attr('string') name;
    @attr('string') type;
    @attr('string') status;

    // Subject (polymorphic asset)
    @attr('string') subject_type;
    @attr('string') subject_uuid;
    @attr('string') subject_name; // computed/serialized on the server

    // Interval definition
    @attr('string') interval_type;
    @attr('number') interval_value;
    @attr('string') interval_unit;
    @attr('number') interval_distance;
    @attr('number') interval_engine_hours;

    // Baseline readings
    @attr('number') last_service_odometer;
    @attr('number') last_service_engine_hours;
    @attr('date') last_service_date;

    // Next-due thresholds
    @attr('date') next_due_date;
    @attr('number') next_due_odometer;
    @attr('number') next_due_engine_hours;

    // Work order defaults
    @attr('string') default_priority;
    @attr('string') default_assignee_type;
    @attr('string') default_assignee_uuid;

    @attr('string') instructions;
    @attr() meta;

    @attr('date') created_at;
    @attr('date') updated_at;

    // Computed display helpers
    get nextDueDate() {
        return this.next_due_date;
    }

    get isActive() {
        return this.status === 'active';
    }

    get isPaused() {
        return this.status === 'paused';
    }
}
