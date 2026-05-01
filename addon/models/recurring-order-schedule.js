import Model, { attr, belongsTo } from '@ember-data/model';

export default class RecurringOrderScheduleModel extends Model {
    @attr('string') public_id;
    @attr('string') uuid;
    @attr('string') name;
    @attr('string') description;
    @attr('string') status;
    @attr('string') timezone;
    @attr('date') starts_at;
    @attr('date') ends_at;
    @attr('string') rrule;
    @attr('date') last_materialized_at;
    @attr('date') materialization_horizon;
    @attr('string') customer_uuid;
    @attr('string') customer_type;
    @attr('string') facilitator_uuid;
    @attr('string') facilitator_type;
    @attr('string') order_config_uuid;
    @attr('string') driver_assigned_uuid;
    @attr('string') vehicle_assigned_uuid;
    @attr('string') service_rate_uuid;
    @attr() template_order_meta;
    @attr() template_payload;
    @attr() template_entities;
    @attr() meta;
    @attr() upcoming_occurrences;
    @attr('date') next_occurrence_at;
    @attr('date') created_at;
    @attr('date') updated_at;

    @belongsTo('customer', { polymorphic: true, async: false }) customer;
    @belongsTo('facilitator', { polymorphic: true, async: false }) facilitator;
    @belongsTo('order-config', { async: false }) order_config;
    @belongsTo('driver', { async: false }) driver_assigned;
    @belongsTo('vehicle', { async: false }) vehicle_assigned;
    @belongsTo('service-rate', { async: false }) service_rate;

    get isActive() {
        return this.status === 'active';
    }

    get isPaused() {
        return this.status === 'paused';
    }

    get isCanceled() {
        return this.status === 'canceled';
    }
}
