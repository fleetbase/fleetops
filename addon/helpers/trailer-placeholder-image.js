import { helper } from '@ember/component/helper';
import getTrailerPlaceholderImage from '../utils/trailer-placeholder-image';

export default helper(function trailerPlaceholderImage() {
    return getTrailerPlaceholderImage();
});
