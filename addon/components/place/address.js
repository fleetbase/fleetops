import Component from '@glimmer/component';
import placeAddressHtml from '../../utils/place-address-html';

export default class PlaceAddressComponent extends Component {
    get place() {
        return this.args.resource ?? this.args.place;
    }

    get addressHtml() {
        return placeAddressHtml(this.place);
    }
}
