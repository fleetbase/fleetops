import { helper } from '@ember/component/helper';
import placeAddressHtml from '../utils/place-address-html';

export default helper(function placeAddressHtmlHelper([place], hash = {}) {
    return placeAddressHtml(place, hash);
});
