import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';

export default class DisplayPlaceComponent extends Component {
    @tracked ref;

    get place() {
        return this.args.place?.place ?? this.args.place ?? this.args.resource;
    }

    get name() {
        if (this.place.name === this.place.street1) {
            return null;
        }

        return this.place.name;
    }

    get cityStatePostalCode() {
        return [this.place.city, this.place.province, this.place.postal_code].filter((value) => !isBlank(value)).join(', ');
    }

    get neighborhoodDistrictBuilding() {
        return [this.place.neighborhood, this.place.district, this.place.building].filter((value) => !isBlank(value)).join(', ');
    }

    @action setupComponent(element) {
        this.ref = element;
    }
}
