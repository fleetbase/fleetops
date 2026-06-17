import Model, { attr, belongsTo } from '@ember-data/model';

export default class InspectionFormModel extends Model {
    @attr('string') public_id;
    @attr('string') name;
    @attr('string') description;
    @attr('string') type;
    @attr('string') status;
    @attr('string') frequency;
    @belongsTo('maintenance-subject', { polymorphic: true, async: false }) subject;
    @attr() items;
    @attr() settings;
    @attr() meta;
    @attr('number') item_count;
    @attr('boolean') is_published;
    @attr('date') published_at;
    @attr('date') created_at;
    @attr('date') updated_at;

    get displayName() {
        return this.name || this.public_id;
    }

    get createdAt() {
        return this.created_at;
    }

    get updatedAt() {
        return this.updated_at;
    }

    get publishedAt() {
        return this.published_at;
    }
}
