import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import getModelName from '@fleetbase/ember-core/utils/get-model-name';

/**
 * Service for managing the state and interactions of the context panel.
 *
 * @class ContextPanelService
 * @memberof @fleetbase/fleetops
 * @extends Service
 */
export default class ContextPanelService extends Service {
    /**
     * Registry mapping model names to their corresponding component details.
     * @type {Object}
     */
    registry = {
        driver: {
            viewing: {
                component: 'driver-panel',
                componentArguments: [{ isResizable: true }, { width: '600px' }],
            },
            editing: {
                component: 'driver-form-panel',
                componentArguments: [{ isResizable: true }, { width: '600px' }],
            },
        },
        vehicle: {
            viewing: {
                component: 'vehicle-panel',
                componentArguments: [{ isResizable: true }, { width: '600px' }],
            },
            editing: {
                component: 'vehicle-form-panel',
                componentArguments: [{ isResizable: true }, { width: '600px' }],
            },
        },
        fleet: {
            viewing: {
                component: 'fleet-panel',
                componentArguments: [{ isResizable: true }, { width: '600px' }],
            },
            editing: {
                component: 'fleet-form-panel',
                componentArguments: [{ isResizable: true }, { width: '600px' }],
            },
        },
        fuelReport: {
            viewing: {
                component: 'fuel-report-panel',
                componentArguments: [{ isResizable: true }, { width: '600px' }],
            },
            editing: {
                component: 'fuel-report-form-panel',
                componentArguments: [{ isResizable: true }, { width: '600px' }],
            },
        },
        vendor: {
            viewing: {
                component: 'vendor-panel',
                componentArguments: [{ isResizable: true }, { width: '600px' }],
            },
            editing: {
                component: 'vendor-form-panel',
                componentArguments: [{ isResizable: true }, { width: '600px' }],
            },
        },
        contact: {
            viewing: {
                component: 'contact-panel',
                componentArguments: [{ isResizable: true }, { width: '600px' }],
            },
            editing: {
                component: 'contact-form-panel',
                componentArguments: [{ isResizable: true }, { width: '600px' }],
            },
        },
        place: {
            viewing: {
                component: 'place-panel',
                componentArguments: [{ isResizable: true }, { width: '600px' }],
            },
            editing: {
                component: 'place-form-panel',
                componentArguments: [{ isResizable: true }, { width: '600px' }],
            },
        },
        issue: {
            viewing: {
                component: 'issue-panel',
                componentArguments: [{ isResizable: true }, { width: '600px' }],
            },
            editing: {
                component: 'issue-form-panel',
                componentArguments: [{ isResizable: true }, { width: '600px' }],
            },
        },
    };

    /**
     * The current context or model object.
     * @type {Object}
     * @tracked
     */
    @tracked currentContext;

    /**
     * The current registry configuration for the current context.
     * @type {Object}
     * @tracked
     */
    @tracked currentContextRegistry;

    /**
     * Arguments for the current context component.
     * @type {Object}
     * @tracked
     */
    @tracked currentContextComponentArguments = {};

    /**
     * Additional options for controlling the context.
     * @type {Object}
     * @tracked
     */
    @tracked contextOptions = {};

    /**
     * Focuses on a given model and intent.
     *
     * @method
     * @param {Object} model - The model to focus on.
     * @param {String} [intent='viewing'] - The type of intent ('viewing' or 'editing').
     * @action
     */
    @action focus(model, intent = 'viewing', options = {}) {
        const modelName = getModelName(model);
        const registry = this.registry[modelName];

        if (registry && registry[intent]) {
            this.currentContext = model;
            this.currentContextRegistry = registry[intent];
            this.currentContextComponentArguments = this.createDynamicArgsFromRegistry(registry[intent], model);
            this.contextOptions = options;
        }
    }

    /**
     * Clears the current context and associated details.
     *
     * @method
     * @action
     */
    @action clear() {
        this.currentContext = null;
        this.currentContextRegistry = null;
        this.currentContextComponentArguments = {};
        this.contextOptions = {};
    }

    /**
     * Changes the intent for the current context.
     *
     * @method
     * @param {String} intent - The new intent.
     * @action
     */
    @action changeIntent(intent) {
        if (this.currentContext) {
            return this.focus(this.currentContext, intent);
        }
    }

    /**
     * Sets an option key-value pair.
     *
     * @method
     * @param {String} key - The option key.
     * @param {*} value - The option value.
     */
    setOption(key, value) {
        this.contextOptions = {
            ...this.contextOptions,
            [key]: value,
        };
    }

    /**
     * Retrieves the value of an option key.
     *
     * @method
     * @param {String} key - The option key.
     * @param {*} [defaultValue=null] - The default value to return if the key is not found.
     * @returns {*}
     */
    getOption(key, defaultValue = null) {
        const value = this.contextOptions[key];

        if (value === undefined) {
            return defaultValue;
        }

        return value;
    }

    /**
     * Generates dynamic arguments for a given registry and model.
     *
     * @method
     * @param {Object} registry - The registry details for a given model.
     * @param {Object} model - The model object.
     * @returns {Object} The dynamic arguments.
     */
    createDynamicArgsFromRegistry(registry, model) {
        // Generate dynamic arguments object
        const dynamicArgs = {};
        const componentArguments = registry.componentArguments || [];

        componentArguments.forEach((arg, index) => {
            if (typeof arg === 'string') {
                dynamicArgs[arg] = model[arg]; // Map string arguments to model properties
            } else if (typeof arg === 'object' && arg !== null) {
                Object.assign(dynamicArgs, arg);
            } else {
                // Handle other types of arguments as needed
                dynamicArgs[`arg${index}`] = arg;
            }
        });

        return dynamicArgs;
    }
}
