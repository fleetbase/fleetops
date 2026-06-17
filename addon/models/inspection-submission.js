import Model, { attr, belongsTo } from '@ember-data/model';

export default class InspectionSubmissionModel extends Model {
    @attr('string') public_id;
    @belongsTo('inspection-form', { async: false, inverse: null }) form;
    @belongsTo('vehicle', { async: false, inverse: null }) vehicle;
    @belongsTo('driver', { async: false, inverse: null }) driver;
    @belongsTo('issue', { async: false, inverse: null }) issue;
    @belongsTo('work-order', { async: false, inverse: null }) work_order;
    @attr() item_results;
    @attr('string') type;
    @attr('string') status;
    @attr('string') result;
    @attr('string') source;
    @attr('number') odometer;
    @attr('number') engine_hours;
    @attr('number') total_items;
    @attr('number') failed_items;
    @attr() location;
    @attr() signature;
    @attr() attachments;
    @attr() meta;
    @attr('string') form_name;
    @attr('string') vehicle_name;
    @attr('string') driver_name;
    @attr('boolean') has_failures;
    @attr('date') started_at;
    @attr('date') submitted_at;
    @attr('date') resolved_at;
    @attr('date') created_at;
    @attr('date') updated_at;

    get displayName() {
        return this.public_id || this.form_name || 'Inspection';
    }

    get createdAt() {
        return this.created_at;
    }

    get updatedAt() {
        return this.updated_at;
    }

    get submittedAt() {
        return this.submitted_at;
    }

    get resolvedAt() {
        return this.resolved_at;
    }
}
