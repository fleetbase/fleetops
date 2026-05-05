import { helper } from '@ember/component/helper';
import placeAddressHtml from '../utils/place-address-html';

export default helper(function placeAddressHtmlHelper([place]) {
    return placeAddressHtml(place);
});
