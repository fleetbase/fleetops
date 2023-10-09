import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action, computed } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import VehiclePanelDetailComponent from './vehicle-panel/details';

export default class VehiclePanelComponent extends Component {
    @service fetch;
    @service modalsManager;
    @service universe;
    @service store;
    @service hostRouter;
    @tracked currentTab;
    @tracked devices = [];
    @tracked deviceApi = {};
    @tracked vehicle;

    get tabs() {
        const registeredTabs = this.universe.getMenuItemsFromRegistry('component:vehicle-panel');
        // this.universe._createMenuItem('Tracking', null, { icon: 'satellite-dish', component: VehiclePanelTrackingComponent }),
        const defaultTabs = [this.universe._createMenuItem('Details', null, { icon: 'circle-info', component: VehiclePanelDetailComponent })];

        if (isArray(registeredTabs)) {
            return [...defaultTabs, ...registeredTabs];
        }

        return defaultTabs;
    }

    @computed('currentTab', 'tabs') get tab() {
        if (this.currentTab) {
            return this.tabs.find(({ slug }) => slug === this.currentTab);
        }

        return null;
    }

    constructor() {
        super(...arguments);
        this.vehicle = this.args.vehicle;
        this.changeTab(this.args.tab || 'details');
    }

    @action async changeTab(tab) {
        this.currentTab = tab;

        if (typeof this.args.onTabChanged === 'function') {
            this.args.onTabChanged(tab);
        }
    }

    @action editVehicle() {
        const { vehicle } = this.args;
        return this.hostRouter.transitionTo('console.fleet-ops.management.vehicles.index.edit', vehicle.public_id);
    }
}
