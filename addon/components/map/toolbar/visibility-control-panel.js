import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { calculateInPlacePosition } from 'ember-basic-dropdown/utils/calculate-position';

export default class MapToolbarVisibilityControlPanel extends Component {
    @service leafletLayerVisibilityManager;
    @service leafletMapManager;
    @tracked areVehiclesHidden = false;
    @tracked arePlacesHidden = false;
    @tracked areDriversHidden = false;

    get livemap() {
        return this.leafletMapManager?._livemap ?? {};
    }

    get vehicles() {
        return this.livemap.vehicles ?? [];
    }

    get drivers() {
        return this.livemap.drivers ?? [];
    }

    get places() {
        return this.livemap.places ?? [];
    }

    #setModelVisible(model, visible) {
        if (visible) {
            this.leafletLayerVisibilityManager.showModelLayer(model);
        } else {
            this.leafletLayerVisibilityManager.hideModelLayer(model);
        }
    }

    #setAllVisible(models, visible) {
        for (const m of models) {
            this.#setModelVisible(m, visible);
        }
    }

    @action calculatePosition(trigger) {
        const position = calculateInPlacePosition(...arguments);
        const rect = trigger.getBoundingClientRect();
        position.style.top = '-0.5rem';
        position.style.left = `calc(${rect.width}px + 0.75rem)`;
        return position;
    }

    @action togglePlaces() {
        this.arePlacesHidden = !this.arePlacesHidden;
        this.#setAllVisible(this.places, !this.arePlacesHidden);
    }

    @action toggleDrivers() {
        this.areDriversHidden = !this.areDriversHidden;
        this.#setAllVisible(this.drivers, !this.areDriversHidden);
    }

    @action toggleDriversByOnline(wantOnline = true) {
        for (const d of this.drivers) {
            this.#setModelVisible(d, Boolean(d?.online) === Boolean(wantOnline));
        }
    }

    @action toggleVehicles() {
        this.areVehiclesHidden = !this.areVehiclesHidden;
        this.#setAllVisible(this.vehicles, !this.areVehiclesHidden);
    }

    @action toggleVehiclesByOnline(wantOnline = true) {
        for (const v of this.vehicles) {
            this.#setModelVisible(v, Boolean(v?.online) === Boolean(wantOnline));
        }
    }
}
