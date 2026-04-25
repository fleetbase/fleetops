import Model, { attr, belongsTo } from '@ember-data/model';

/**
 * Local maintenance-schedule model used by the fleetops engine.
 * The canonical model with full computed properties lives in fleetops-data.
 * This local copy adds the polymorphic @belongsTo relationships so the
 * engine's form and details components can use relationship accessors
 * directly instead of raw _type / _uuid attrs.
 */
export default class MaintenanceScheduleModel extends Model {
    // Identification
    @attr('string') public_id;
    @attr('string') name;
    @attr('string') type;
    @attr('string') status;

    // Polymorphic subject (the asset this schedule applies to)
    @belongsTo('maintenance-subject', { polymorphic: true, async: false }) subject;

    // Polymorphic default_assignee (who should be assigned to generated work orders)
    @belongsTo('facilitator', { polymorphic: true, async: false }) default_assignee;

    // Interval definition
    @attr('string') interval_method;
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
