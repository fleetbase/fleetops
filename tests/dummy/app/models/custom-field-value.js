import Model, { attr } from '@ember-data/model';

/**
 * Six fleetops-data models declare `@hasMany('custom-field-value')`, but no package in the
 * workspace defines that model, so materializing the relationship throws "No model was found for
 * 'custom-field-value'". The dummy app supplies a minimal one so a rendering test can create the
 * records those relationships hang off. See DEFECTS #75.
 */
export default class CustomFieldValueModel extends Model {
    @attr('string') custom_field_uuid;
    @attr('string') subject_uuid;
    @attr('string') subject_type;
    @attr('string') value;
    @attr('string') value_type;
}
