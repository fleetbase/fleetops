import Component from '@glimmer/component';
import EmberObject from '@ember/object';
import Evented from '@ember/object/evented';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { dasherize } from '@ember/string';
import { task } from 'ember-concurrency';
import isModel from '@fleetbase/ember-core/utils/is-model';
import getModelName from '@fleetbase/ember-core/utils/get-model-name';
import contextComponentCallback from '@fleetbase/ember-core/utils/context-component-callback';
import applyContextComponentArguments from '@fleetbase/ember-core/utils/apply-context-component-arguments';
import OrderConfigManagerDetailsComponent from './order-config-manager/details';
import OrderConfigManagerCustomFieldsComponent from './order-config-manager/custom-fields';
import OrderConfigManagerActivityFlowComponent from './order-config-manager/activity-flow';
import OrderConfigManagerLifecycleComponent from './order-config-manager/lifecycle';
import OrderConfigManagerEntitiesComponent from './order-config-manager/entities';
import OrderConfigManagerPoliciesComponent from './order-config-manager/policies';
import OrderConfigManagerOverviewComponent from './order-config-manager/overview';
import findActiveTab from '../utils/find-active-tab';

const configManagerContext = EmberObject.extend(Evented);
export default class OrderConfigManagerComponent extends Component {
    @service universe;
    @service('universe/menu-service') menuService;
    @service notifications;
    @service modalsManager;
    @service fetch;
    @service store;
    @service intl;
    @tracked configs = [];
    @tracked currentConfig;
    @tracked tab;
    @tracked configManagerContext;
    @tracked context;
    @tracked contextModel;
    @tracked ready = false;
    @tracked childTaskRunning = false;

    /**
     * Returns the array of tabs available for the panel.
     *
     * @type {Array}
     */
    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:order-config-manager');
        const tab = (key, icon, component) => this.universe._createMenuItem(this.intl.t(`order-config-manager.tabs.${key}`), null, { icon, component, slug: key });
        const defaultTabs = [
            tab('overview', 'list-check', OrderConfigManagerOverviewComponent),
            tab('details', 'circle-info', OrderConfigManagerDetailsComponent),
            tab('custom-fields', 'rectangle-list', OrderConfigManagerCustomFieldsComponent),
            tab('activity-flow', 'diagram-project', OrderConfigManagerActivityFlowComponent),
            tab('lifecycle', 'arrows-spin', OrderConfigManagerLifecycleComponent),
            tab('policies', 'sliders', OrderConfigManagerPoliciesComponent),
            tab('entities', 'boxes-packing', OrderConfigManagerEntitiesComponent),
        ];

        if (isArray(registeredTabs)) {
            return [...defaultTabs, ...registeredTabs];
        }

        return defaultTabs;
    }

    /**
     * Constructs the component and applies initial state.
     */
    constructor(owner, { tab, context, contextModel }) {
        super(...arguments);
        applyContextComponentArguments(this);

        this.context = context;
        this.contextModel = contextModel;
        this.configManagerContext = this.#createManagerContext();
        this.tab = findActiveTab(this.tabs, tab);
        this.loadOrderConfigs.perform();
    }

    /**
     * Creates a contextual object for the order config manager.
     */
    #createManagerContext() {
        const component = this;
        const ctxmc = configManagerContext.create();
        ctxmc.loadOrderConfigs = component.loadOrderConfigs;
        ctxmc.createNewOrderConfig = component.createNewOrderConfig;
        ctxmc.selectConfig = component.selectConfig;
        ctxmc.unready = function () {
            component.ready = false;
        };
        ctxmc.ready = function () {
            component.ready = true;
        };
        ctxmc.childTaskStarted = function () {
            component.childTaskRunning = true;
        };
        ctxmc.childTaskEnded = function () {
            component.childTaskRunning = false;
        };

        return ctxmc;
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
    @task *loadOrderConfigs(options = {}) {
        try {
            // Always fetch: `findAll` otherwise resolves with whatever subset of configs the store
            // already holds (e.g. from an order's embedded config) and only refreshes in the background.
            const configs = yield this.store.findAll('order-config', { reload: true }).then(Array.from);
            let currentConfig;
            if (isArray(configs) && configs.length > 0) {
                currentConfig = configs.find((c) => c.id === this.args.orderConfig);
                if (!currentConfig) {
                    currentConfig = configs.find((c) => c.key === 'transport') ?? configs[0];
                }

                this.selectConfig(currentConfig);
            }

            if (typeof options.onAfter === 'function') {
                options.onAfter(configs, currentConfig);
            }
            this.configs = configs;
            this.ready = true;

            return configs;
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    /**
     * Handle anonymous context change.
     *
     * @memberof OrderConfigManagerComponent
     */
    @action onContextChanged(context) {
        const isValidContext = context && isModel(context);
        if (context === null || !isValidContext) {
            this.context = undefined;
            this.contextModel = undefined;
            contextComponentCallback(this, 'onContextChanged', this.context, this.contextModel);
            return;
        }

        this.context = context.get('id');
        this.contextModel = getModelName(context);
        contextComponentCallback(this, 'onContextChanged', this.context, this.contextModel);
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
    @action async createNewOrderConfig() {
        const orderConfig = this.store.createRecord('order-config', {
            tags: [],
        });
        const templates = await this.loadTemplates();
        const blank = {
            key: 'blank',
            name: this.intl.t('order-config-manager.templates.blank.name'),
            description: this.intl.t('order-config-manager.templates.blank.description'),
            steps: [],
            summary: null,
        };
        const describe = (template) => {
            const key = `order-config-manager.templates.${template.key}`;
            const tr = (suffix, fallback) => (this.intl.exists(`${key}.${suffix}`) ? this.intl.t(`${key}.${suffix}`) : fallback);
            const summary = template.summary;
            return {
                ...template,
                name: tr('name', template.name),
                description: tr('description', template.description),
                namePlaceholder: tr('name-placeholder', this.intl.t('order-config-manager.new-config.name-placeholder')),
                icon: { transport: 'truck', passenger: 'user', blank: 'plus' }[template.key] ?? 'diagram-project',
                summaryText: summary
                    ? this.intl.t('order-config-manager.new-config.summary', { steps: summary.steps, policies: summary.policies, triggers: summary.triggers, fields: summary.custom_fields })
                    : this.intl.t('order-config-manager.new-config.summary-blank'),
                facts: [1, 2, 3].map((n) => tr(`fact-${n}`, null)).filter(Boolean),
            };
        };
        const choices = [...templates.map(describe), describe(blank)];
        const pick = (key) => {
            const template = choices.find((item) => item.key === key);
            this.modalsManager.setOption('template', key);
            this.modalsManager.setOption('current', template);
            this.modalsManager.setOption('acceptButtonDisabled', !template);
            this.modalsManager.setOption(
                'acceptButtonText',
                template ? this.intl.t('order-config-manager.new-config.create-from', { template: template.name }) : this.intl.t('order-config-manager.new-config.create')
            );
        };
        this.modalsManager.show('modals/new-order-config', {
            templates: choices,
            template: null,
            current: null,
            selectTemplate: pick,
            acceptButtonDisabled: true,
            acceptButtonText: this.intl.t('order-config-manager.new-config.create'),
            modalClass: 'modal-lg',
            title: this.intl.t('order-config-manager.new-config.title'),
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
                    return this.notifications.warning(this.intl.t('order-config-manager.create-warning-message'));
                }

                modal.startLoading();
                const template = this.modalsManager.getOption('template');
                if (template && template !== 'blank') {
                    return this.createFromTemplate(template, orderConfig, modal);
                }

                orderConfig.set('key', dasherize(orderConfig.name));
                orderConfig
                    .save()
                    .then((newOrderConfig) => {
                        this.notifications.success(this.intl.t('order-config-manager.create-success-message'));
                        this.loadOrderConfigs.perform({
                            onAfter: () => {
                                this.selectConfig(newOrderConfig);
                            },
                        });
                        modal.done();
                    })
                    .catch((error) => {
                        modal.stopLoading();
                        this.notifications.serverError(error);
                    });
            },
            decline: (modal) => {
                orderConfig.destroyRecord();
                modal.done();
            },
        });
    }

    /**
     * The order config templates the server offers, or none when they can't be loaded.
     */
    async loadTemplates() {
        try {
            const { templates } = await this.fetch.get('order-configs/capabilities');
            return templates ?? [];
        } catch {
            return [];
        }
    }

    /**
     * Create the config from a template under the name entered, then select it.
     */
    async createFromTemplate(template, orderConfig, modal) {
        try {
            const { order_config } = await this.fetch.post('order-configs/from-template', { template, name: orderConfig.name });
            orderConfig.unloadRecord();
            this.notifications.success(this.intl.t('order-config-manager.create-success-message'));
            this.loadOrderConfigs.perform({
                onAfter: (configs = []) => {
                    const created = configs.find((config) => config.id === order_config?.uuid || config.public_id === order_config?.public_id);
                    if (created) {
                        this.selectConfig(created);
                    }
                },
            });
            modal.done();
        } catch (error) {
            modal.stopLoading();
            this.notifications.serverError(error);
        }
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
        this.configManagerContext.set('currentConfig', config);
        this.configManagerContext.trigger('onConfigChanged', config);
        contextComponentCallback(this, 'onConfigChanged', ...arguments);
    }

    /**
     * Handles the deletion process of an order configuration.
     *
     * This action is called when an order configuration is in the process of being deleted.
     * It deselects the current configuration and performs additional operations defined
     * in 'contextComponentCallback'.
     */
    @action onConfigDeleting() {
        this.configManagerContext.trigger('onConfigDeleting');
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
        this.configManagerContext.trigger('onConfigDeleted');
        contextComponentCallback(this, 'onConfigDeleted', ...arguments);
    }

    /**
     * Performs operations after an order configuration has been updated.
     *
     * This action is triggered when an order configuration update occurs.
     * It primarily executes additional operations defined in 'contextComponentCallback'.
     */
    @action onConfigUpdated() {
        this.configManagerContext.trigger('onConfigUpdated');
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
        this.configManagerContext.trigger('onLoad');
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
        this.tab = findActiveTab(this.tabs, tab);
        this.configManagerContext.trigger('onTabChanged');
        contextComponentCallback(this, 'onTabChanged', tab);
    }

    /**
     * Handles edit action for the place.
     *
     * @method
     * @action
     */
    @action onEdit() {
        return contextComponentCallback(this, 'onEdit');
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
}
