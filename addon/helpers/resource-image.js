import { helper } from '@ember/component/helper';
import { resolveResourceImage } from '../utils/placeholder-images';

/**
 * `{{resource-image @resource.photo_url "driver"}}` — the record's photo, or the styled
 * placeholder for its resource type when the photo is missing or a legacy default.
 */
export default helper(function resourceImage([url, type]) {
    return resolveResourceImage(url, type);
});
