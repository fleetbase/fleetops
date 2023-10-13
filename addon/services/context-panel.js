import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import getModelName from '@fleetbase/ember-core/utils/get-model-name';

/**
 * Service to manage the context panel in the application.
 */
export default class ContextPanelService extends Service {
    /**
     * Registry to map models to their corresponding components and arguments.
     */
    registry = {
        driver: {
            viewing: {
                component: 'driver-panel',
                componentArguments: [{ isResizable: true }, { width: '550px' }],
            },
            editing: {
                component: 'driver-form-panel',
                componentArguments: [{ isResizable: true }, { width: '550px' }],
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
    };

    /**
     * The current context model.
     */
    @tracked currentContext;

    /**
     * The current context registry.
     */
    @tracked currentContextRegistry;

    /**
     * The current context component arguments.
     */
    @tracked currentContextComponentArguments = {};

    /**
     * Focuses on a given model with a specific intent.
     * @param {Object} model - The model to focus on.
     * @param {string} [intent='viewing'] - The intent for focusing (e.g., 'viewing', 'editing').
     */
    @action focus(model, intent = 'viewing') {
        const modelName = getModelName(model);
        const registry = this.registry[modelName];

        if (registry && registry[intent]) {
            this.currentContext = model;
            this.currentContextRegistry = registry[intent];
            this.currentContextComponentArguments = this.createDynamicArgsFromRegistry(registry[intent], model);
        }
    }

    /**
     * Clears the current context.
     */
    @action clear() {
        this.currentContext = null;
        this.currentContextRegistry = null;
        this.currentContextComponentArguments = {};
    }

    @action changeIntent(intent) {
        if (this.currentContext) {
            return this.focus(this.currentContext, intent);
        }
    }

    /**
     * Creates dynamic arguments from the registry.
     * @param {Object} registry - The registry for the current context.
     * @param {Object} model - The model to focus on.
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
