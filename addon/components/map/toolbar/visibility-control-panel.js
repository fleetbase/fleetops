import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { alias } from '@ember/object/computed';
import { calculateInPlacePosition } from 'ember-basic-dropdown/utils/calculate-position';

export default class MapToolbarVisibilityControlPanelComponent extends Component {
    @service leafletLayerVisibilityManager;
    @service leafletMapManager;

    @alias('leafletMapManager._livemap.places') places;
    @alias('leafletMapManager._livemap.drivers') drivers;
    @alias('leafletMapManager._livemap.vehicles') vehicles;

    @action calculatePosition(trigger) {
        const position = calculateInPlacePosition(...arguments);
        const rect = trigger.getBoundingClientRect();

        position.style.top = '-0.5rem';
        position.style.left = `calc(${rect.width}px + 0.75rem)`;

        return position;
    }

    @action toggleCategory(category) {
        this.leafletLayerVisibilityManager.toggleCategory(category);
    }

    @action toggleVehiclesByOnline(online = 1) {
        const wantOnline = Boolean(online);

        this.vehicles.forEach((vehicle) => {
            if (Boolean(vehicle.online) === wantOnline) {
                this.leafletLayerVisibilityManager.showModelLayer(vehicle);
            } else {
                this.leafletLayerVisibilityManager.hideModelLayer(vehicle);
            }
        });
    }
}
