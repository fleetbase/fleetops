import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency-decorators';
import contextComponentCallback from '../utils/context-component-callback';
import applyContextComponentArguments from '../utils/apply-context-component-arguments';
import OrderConfigManagerDetailsComponent from './order-config-manager/details';
import OrderConfigManagerCustomFieldsComponent from './order-config-manager/custom-fields';
import OrderConfigManagerActivityFlowComponent from './order-config-manager/activity-flow';
import OrderConfigManagerEntitiesComponent from './order-config-manager/entities';

export default class OrderConfigManagerComponent extends Component {
    @service universe;
    @service notifications;
    @service modalsManager;
    @service store;
    @service intl;
    @tracked configs = [];
    @tracked currentConfig;
    @tracked tab;

    /**
     * Returns the array of tabs available for the panel.
     *
     * @type {Array}
     */
    get tabs() {
        const registeredTabs = this.universe.getMenuItemsFromRegistry('component:order-config-manager');
        const defaultTabs = [
            this.universe._createMenuItem('Details', null, { icon: 'circle-info', component: OrderConfigManagerDetailsComponent }),
            this.universe._createMenuItem('Custom Fields', null, { icon: 'rectangle-list', component: OrderConfigManagerCustomFieldsComponent }),
            this.universe._createMenuItem('Activity Flow', null, { icon: 'diagram-project', component: OrderConfigManagerActivityFlowComponent }),
            this.universe._createMenuItem('Entities', null, { icon: 'boxes-packing', component: OrderConfigManagerEntitiesComponent }),
        ];

        if (isArray(registeredTabs)) {
            return [...defaultTabs, ...registeredTabs];
        }

        return defaultTabs;
    }

    /**
     * Constructs the component and applies initial state.
     */
    constructor() {
        super(...arguments);
        applyContextComponentArguments(this);

        this.tab = this.getTabUsingSlug(this.args.tab);
        this.loadOrderConfigs.perform();
    }

    /**
     * Loads all available order configs asynchronously.
     *
     * @returns {void}
     * @memberof OrderConfigManagerComponent
     * @method loadOrderConfigs
     * @instance
     * @task
     * @generator
     */
    @task *loadOrderConfigs() {
        this.configs = yield this.store.findAll('order-config');

        if (isArray(this.configs) && this.configs.length > 0) {
            this.currentConfig = this.configs[0];
        }
    }

    /**
     * Creates a new order configuration and displays a modal for further interaction.
     *
     * This action initializes a new 'order-config' record with default values and
     * displays a modal to the user for creating a new order configuration. The modal
     * is configured with various properties including titles, button icons, and a callback
     * for the confirm action. The confirm action includes validation and saving of the
     * new order configuration, along with success and warning notifications.
     */
    @action createNewOrderConfig() {
        const orderConfig = this.store.createRecord('order-config', {
            tags: [],
        });

        this.modalsManager.show('modals/new-order-config', {
            title: this.intl.t('fleet-ops.component.order-config-manager.create-new-title'),
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            orderConfig,
            addTag: (tag) => {
                orderConfig.addTag(tag);
            },
            removeTag: (index) => {
                orderConfig.removeTag(index);
            },
            confirm: (modal) => {
                if (!orderConfig.name) {
                    return this.notifications.warning(this.intl.t('fleet-ops.component.order-config-manager.create-warning-message'));
                }

                modal.startLoading();
                return orderConfig.save().then(() => {
                    this.notifications.success(this.intl.t('fleet-ops.component.order-config-manager.create-success-message'));
                    this.loadOrderConfigs.perform();
                });
            },
        });
    }

    /**
     * Selects a specific order configuration.
     *
     * This action sets the 'currentConfig' property of the component to the
     * specified configuration object.
     *
     * @param {Object} config - The order configuration object to be selected.
     */
    @action selectConfig(config) {
        this.currentConfig = config;
    }

    /**
     * Handles the deletion process of an order configuration.
     *
     * This action is called when an order configuration is in the process of being deleted.
     * It deselects the current configuration and performs additional operations defined
     * in 'contextComponentCallback'.
     */
    @action onConfigDeleting() {
        this.selectConfig(null);
        contextComponentCallback(this, 'onConfigDeleting', ...arguments);
    }

    /**
     * Executes actions after an order configuration has been deleted.
     *
     * Once a configuration is deleted, this action reloads the order configurations and
     * executes additional operations defined in 'contextComponentCallback'.
     */
    @action onConfigDeleted() {
        this.loadOrderConfigs.perform();
        contextComponentCallback(this, 'onConfigDeleted', ...arguments);
    }

    /**
     * Performs operations after an order configuration has been updated.
     *
     * This action is triggered when an order configuration update occurs.
     * It primarily executes additional operations defined in 'contextComponentCallback'.
     */
    @action onConfigUpdated() {
        contextComponentCallback(this, 'onConfigUpdated', ...arguments);
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
     * Handles edit action for the place.
     *
     * @method
     * @action
     */
    @action onEdit() {
        const isActionOverrided = contextComponentCallback(this, 'onEdit');

        if (!isActionOverrided) {
            this.contextPanel.clear();
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
        return contextComponentCallback(this, 'onPressCancel');
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
