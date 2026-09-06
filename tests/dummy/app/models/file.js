import Model, { attr } from '@ember-data/model';

/**
 * Stand-in for the host console's `file` model (see DEFECTS.md #6).
 *
 * `admin/avatar-management`, `avatar-manager` and `avatar-picker` query and create `file` records;
 * the console defines the model, `@fleetbase/fleetops-data` does not. Only the attributes those
 * components and their templates read are declared.
 */
export default class FileModel extends Model {
    @attr('string') uuid;
    @attr('string') public_id;
    @attr('string') name;
    @attr('string') original_filename;
    @attr('string') url;
    @attr('string') path;
    @attr('string') type;
    @attr('string') content_type;
    @attr('string') caption;
    @attr('number') file_size;
    @attr('string') created_at;
    @attr('string') updated_at;
}
