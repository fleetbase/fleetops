import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import contextComponentCallback from '../utils/context-component-callback';
import applyContextComponentArguments from '../utils/apply-context-component-arguments';
import LiveMapDrawerVehicleListingComponent from './live-map-drawer/vehicle-listing';
import LiveMapDrawerDriverListingComponent from './live-map-drawer/driver-listing';
import LiveMapDrawerPlaceListingComponent from './live-map-drawer/place-listing';

export default class LiveMapDrawerComponent extends Component {
    /**
     * Universe service for managing global data and settings.
     *
     * @type {Service}
     */
    @service universe;

    /**
     * The current active tab.
     *
     * @type {Object}
     * @tracked
     */
    @tracked tab;

    /**
     * The drawer component context api.
     *
     * @type {Object}
     * @tracked
     */
    @tracked drawer;

    /**
     * Returns the array of tabs available for the drawer.
     *
     * @type {Array}
     */
    get tabs() {
        const registeredTabs = this.universe.getMenuItemsFromRegistry('component:live-map-drawer');
        const defaultTabs = [
            this.universe._createMenuItem('Vehicles', null, { icon: 'car', component: LiveMapDrawerVehicleListingComponent }),
            this.universe._createMenuItem('Drivers', null, { icon: 'id-card', component: LiveMapDrawerDriverListingComponent }),
            this.universe._createMenuItem('Places', null, { icon: 'building', component: LiveMapDrawerPlaceListingComponent }),
        ];

        if (isArray(registeredTabs)) {
            return [...defaultTabs, ...registeredTabs];
        }

        return defaultTabs;
    }

    /**
     * Initializes the vehicle panel component.
     */
    constructor() {
        super(...arguments);
        this.tab = this.getTabUsingSlug(this.args.tab);
        applyContextComponentArguments(this);
    }

    /**
     * Sets the drawer component context api.
     *
     * @param {Object} drawerApi
     * @memberof LiveMapDrawerComponent
     */
    @action setDrawerContext(drawerApi) {
        this.drawer = drawerApi;

        if (typeof this.args.onDrawerReady === 'function') {
            this.args.onDrawerReady(...arguments);
        }
    }

    /**
     * Handles changing the active tab.
     *
     * @method
     * @param {String} tab - The new tab to switch to.
     * @action
     */
    @action onTabChanged(tab) {
        this.tab = this.getTabUsingSlug(tab);
        contextComponentCallback(this, 'onTabChanged', tab);
    }

    /**
     * Finds and returns a tab based on its slug.
     *
     * @param {String} tabSlug - The slug of the tab.
     * @returns {Object|null} The found tab or null.
     */
    getTabUsingSlug(tabSlug) {
        if (tabSlug) {
            return this.tabs.find(({ slug }) => slug === tabSlug);
        }

        return this.tabs[0];
    }
}
