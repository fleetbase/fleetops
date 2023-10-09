import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action, computed } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import DriverPanelDetailComponent from './driver-panel/details';

export default class DriverPanelComponent extends Component {
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
        const registeredTabs = this.universe.getMenuItemsFromRegistry('component:driver-panel');
        // this.universe._createMenuItem('Tracking', null, { icon: 'satellite-dish', component: DriverPanelTrackingComponent }),
        const defaultTabs = [this.universe._createMenuItem('Details', null, { icon: 'circle-info', component: DriverPanelDetailComponent })];

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

    @action editDriver() {
        const { driver } = this.args;
        return this.hostRouter.transitionTo('console.fleet-ops.management.drivers.index.edit', driver.public_id);
    }
}
