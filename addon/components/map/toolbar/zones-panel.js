import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { calculateInPlacePosition } from 'ember-basic-dropdown/utils/calculate-position';

export default class MapToolbarZonesPanelComponent extends Component {
    @service leafletMapManager;
    @service serviceAreaActions;
    @service geofence;

    get serviceAreas() {
        return this.serviceAreaActions.serviceAreas ?? [];
    }

    @action calculatePosition(trigger) {
        const position = calculateInPlacePosition(...arguments);
        const rect = trigger.getBoundingClientRect();

        position.style.top = '-0.5rem';
        position.style.left = `calc(${rect.width}px + 0.75rem)`;

        return position;
    }
}
