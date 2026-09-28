import Service, { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

/**
 * Resolves an extension-owned presentation profile for an order.
 *
 * A profile lets an extension compose the order form, details and actions for orders
 * whose Order Config opts in via `order_config.meta.presentation_profile`. When no
 * profile applies every consumer renders the standard Fleet-Ops presentation.
 *
 * Extensions register profiles through the registry service so registration does not
 * depend on the Fleet-Ops engine having loaded:
 *
 *   registryService.register('fleet-ops:order-presentation', 'profiles', 'my-profile', {
 *       id: 'my-profile',
 *       isEnabled() { return true; },
 *       form: { sections: ['details', 'route', new ExtensionComponent('@org/engine', 'my-section'), 'notes'] },
 *       details: { sections: [...] },
 *       hidden: { fields: ['driver', 'dispatch'], actions: ['dispatch', 'assign_driver'] },
 *       prepare(order) {},
 *   });
 *
 * Native section names — form: details, route, payload, service-rate, notes, documents,
 * orchestrator-constraints, metadata, custom-fields. Details: activity, detail, custom-fields,
 * purchase-rate, tracking, proof, notes, integrated-vendor-details, route, payload,
 * documents, comments, metadata.
 */
export const ORDER_PRESENTATION_REGISTRY = 'fleet-ops:order-presentation';

export default class OrderPresentationService extends Service {
    @service('universe/registry-service') registryService;

    get profiles() {
        return this.registryService.getRegistry(ORDER_PRESENTATION_REGISTRY, 'profiles') ?? [];
    }

    /**
     * Returns the presentation profile applying to an order or order config, or null.
     *
     * @param {Object} orderOrConfig an order (with `order_config`) or an order-config
     * @returns {Object|null}
     */
    profileFor(orderOrConfig) {
        const orderConfig = this.#resolveOrderConfig(orderOrConfig);
        const profileId = orderConfig?.meta?.presentation_profile;
        if (!profileId) {
            return null;
        }

        const profile = this.profiles.find((candidate) => candidate?.id === profileId);
        if (!profile) {
            return null;
        }

        if (typeof profile.isEnabled === 'function' && profile.isEnabled(orderConfig) !== true) {
            return null;
        }

        return profile;
    }

    /**
     * Returns the ordered sections of a profile surface ("form" or "details").
     */
    sectionsFor(profile, surface) {
        const sections = profile?.[surface]?.sections;
        return isArray(sections) ? sections : [];
    }

    /**
     * Returns renderable section descriptors for a surface ("form" or "details").
     * Native sections resolve to `order/<surface>/<name>`; anything else is an ExtensionComponent.
     */
    renderableSectionsFor(profile, surface) {
        return this.sectionsFor(profile, surface).map((section, index) => {
            if (typeof section === 'string') {
                return { key: `native:${section}`, native: true, name: section, componentName: `order/${surface}/${section}` };
            }

            return { key: `extension:${section?.path ?? section?.name ?? index}`, native: false, component: section };
        });
    }

    /**
     * Determines if a field or action is hidden by the profile applying to the subject.
     *
     * @param {Object} orderOrConfig
     * @param {String} group "fields" or "actions"
     * @param {String} key
     */
    isHidden(orderOrConfig, group, key) {
        const profile = this.profileFor(orderOrConfig);
        const hidden = profile?.hidden?.[group];
        return isArray(hidden) && hidden.includes(key);
    }

    hiddenFieldsFor(orderOrConfig) {
        const hidden = this.profileFor(orderOrConfig)?.hidden?.fields;
        return isArray(hidden) ? hidden : [];
    }

    /**
     * Lets the profile normalize an order when it starts to apply (e.g. clear dispatch-only values).
     */
    prepare(order) {
        const profile = this.profileFor(order);
        if (profile && typeof profile.prepare === 'function') {
            profile.prepare(order);
        }
        return profile;
    }

    #resolveOrderConfig(orderOrConfig) {
        if (!orderOrConfig) {
            return null;
        }

        // An order-config has a flow; an order references its config.
        if (orderOrConfig.flow !== undefined && orderOrConfig.order_config === undefined) {
            return orderOrConfig;
        }

        return orderOrConfig.order_config ?? null;
    }
}
