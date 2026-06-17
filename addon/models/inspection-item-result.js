import Model, { attr, belongsTo } from '@ember-data/model';

export default class InspectionItemResultModel extends Model {
    @belongsTo('inspection-submission', { async: false, inverse: null }) submission;
    @belongsTo('issue', { async: false, inverse: null }) issue;
    @belongsTo('work-order', { async: false, inverse: null }) work_order;
    @attr('string') item_key;
    @attr('string') label;
    @attr('string') category;
    @attr('string') status;
    @attr('string') severity;
    @attr('boolean') passed;
    @attr('string') comments;
    @attr() photos;
    @attr() meta;
    @attr('date') created_at;
    @attr('date') updated_at;
}
