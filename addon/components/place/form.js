import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class PlaceFormComponent extends Component {
    @tracked coordinatesInput;

    @action onAutocomplete(selected) {
        this.args.resource.setProperties({ ...selected });

        if (this.coordinatesInput && selected.location) {
            this.coordinatesInput.updateCoordinates(selected.location);
        }
    }
}
