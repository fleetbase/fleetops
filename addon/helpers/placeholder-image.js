import { helper } from '@ember/component/helper';
import { getPlaceholderImage } from '../utils/placeholder-images';

export default helper(function placeholderImage([type]) {
    return getPlaceholderImage(type);
});
