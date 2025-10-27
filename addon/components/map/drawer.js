import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';

export default class MapDrawerComponent extends Component {
    @service mapDrawer;
    @service universe;

    get tabs() {
        const registeredTabs = this.universe.getMenuItemsFromRegistry('fleet-ops:component:map:drawer');
        return [
            this.universe._createMenuItem('Vehicles', null, { icon: 'car', component: 'map/drawer/vehicle-listing' }),
            this.universe._createMenuItem('Drivers', null, { icon: 'id-card', component: 'map/drawer/driver-listing' }),
            this.universe._createMenuItem('Places', null, { icon: 'building', component: 'map/drawer/place-listing' }),
            this.universe._createMenuItem('Positions', null, { icon: 'map-marker', component: 'map/drawer/position-listing' }),
            this.universe._createMenuItem('Events', null, { icon: 'stream', component: 'map/drawer/device-event-listing' }),
            ...(isArray(registeredTabs) ? registeredTabs : []),
        ];
    }

    @action setDrawerContext(drawerContextApi) {
        this.mapDrawer.setDrawer(drawerContextApi, this);
    }
}
