import getModelName from '@fleetbase/ember-core/utils/get-model-name';

/**
 * Applies context and dynamic arguments to a given component.
 *
 * @param {Component} component - The component to which context and arguments will be applied.
 */
export default function applyContextComponentArguments(component) {
    const { context, dynamicArgs = {} } = component.args;

    // Apply context model if available
    if (context) {
        const contextModelName = getModelName(context);
        if (contextModelName) {
            component[contextModelName] = context;
        }
    }

    // Execute any apply callback present in dynamic arguments
    const { applyCallback } = dynamicArgs;
    if (typeof applyCallback === 'function') {
        applyCallback(component);
    }

    // Apply other dynamic arguments to the component
    for (const [key, value] of Object.entries(dynamicArgs)) {
        if (key !== 'applyCallback') {
            component[key] = value;
        }
    }
}
