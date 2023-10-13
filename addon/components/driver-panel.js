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
    @service contextPanel;
    @tracked currentTab;
    @tracked devices = [];
    @tracked deviceApi = {};
    @tracked driver;

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
        this.driver = this.args.driver;
        this.changeTab(this.args.tab);
        this.applyDynamicArguments();
    }

    applyDynamicArguments() {
        // Apply context if available
        if (this.args.context) {
            this.driver = this.args.context;
        }

        // Apply dynamic arguments if available
        if (this.args.dynamicArgs) {
            const keys = Object.keys(this.args.dynamicArgs);

            keys.forEach((key) => {
                this[key] = this.args.dynamicArgs[key];
            });
        }
    }

    @action async changeTab(tab = 'details') {
        this.currentTab = tab;

        if (typeof this.args.onTabChanged === 'function') {
            this.args.onTabChanged(tab);
        }
    }

    @action editDriver() {
        this.contextPanel.focus(this.driver, 'editing');
    }
}
