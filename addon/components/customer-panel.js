import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import CustomerPanelDetailComponent from './customer-panel/details';
import contextComponentCallback from '@fleetbase/ember-core/utils/context-component-callback';
import applyContextComponentArguments from '@fleetbase/ember-core/utils/apply-context-component-arguments';

export default class CustomerPanelComponent extends Component {
    @service fetch;
    @service modalsManager;
    @service universe;
    @service store;
    @service hostRouter;
    @service contextPanel;

    /**
     * The current active tab.
     *
     * @type {Object}
     * @tracked
     */
    @tracked tab;

    /**
     * The customer being displayed or edited.
     *
     * @type {ContactModel}
     * @tracked
     */
    @tracked customer;

    /**
     * Returns the array of tabs available for the panel.
     *
     * @type {Array}
     */
    get tabs() {
        const registeredTabs = this.universe.getMenuItemsFromRegistry('fleet-ops:component:customer-panel');
        const defaultTabs = [this.universe._createMenuItem('Details', null, { icon: 'circle-info', component: CustomerPanelDetailComponent })];

        if (isArray(registeredTabs)) {
            return [...defaultTabs, ...registeredTabs];
        }

        return defaultTabs;
    }

    /**
     * Initializes the customer panel component.
     */
    constructor() {
        super(...arguments);
        this.customer = this.args.customer;
        this.tab = this.getTabUsingSlug(this.args.tab);
        applyContextComponentArguments(this);
    }

    /**
     * Sets the overlay context.
     *
     * @action
     * @param {OverlayContextObject} overlayContext
     */
    @action setOverlayContext(overlayContext) {
        this.context = overlayContext;
        contextComponentCallback(this, 'onLoad', ...arguments);
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
     * Handles edit action for the customer.
     *
     * @method
     * @action
     */
    @action onEdit() {
        const isActionOverrided = contextComponentCallback(this, 'onEdit', this.customer);

        if (!isActionOverrided) {
            this.contextPanel.focus(this.customer, 'editing', {
                onAfterSave: () => {
                    this.contextPanel.clear();
                },
            });
        }
    }

    /**
     * Handles the cancel action.
     *
     * @method
     * @action
     * @returns {Boolean} Indicates whether the cancel action was overridden.
     */
    @action onPressCancel() {
        return contextComponentCallback(this, 'onPressCancel', this.customer);
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
