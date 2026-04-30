import Component from '@glimmer/component';
import { isBlank } from '@ember/utils';

export default class PlaceAddressComponent extends Component {
    get place() {
        return this.args.resource ?? this.args.place;
    }

    get name() {
        if (this.place.name === this.place.street1) {
            return null;
        }

        return this.place.name;
    }

    get cityStatePostalCode() {
        [this.place.city, this.place.province, this.place.postal_code].filter(isBlank).join(', ');
    }
}
