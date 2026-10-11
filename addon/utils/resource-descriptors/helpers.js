import { first, relation } from '@fleetbase/fleetops-data/utils/resource-descriptors/helpers';

/**
 * The building blocks the FleetOps descriptors share. The ones a descriptor
 * needs to read a record live in `@fleetbase/fleetops-data`, so the shared
 * resources render the same in every extension; this module re-exports them
 * and adds the openers that only make sense with the FleetOps engine's own
 * services and routes.
 */
export {
    present,
    first,
    lookupService,
    relation,
    photo,
    icon,
    badge,
    badges,
    fact,
    relatedFact,
    dateLabel,
    typeLabel,
    money,
    join,
    polymorphicType,
} from '@fleetbase/fleetops-data/utils/resource-descriptors/helpers';

/** A coloured tile for records that carry their own colour (service areas, zones). */
export function colourTile(record, name) {
    const colour = first(record, 'color', 'stroke_color');

    return colour ? { icon: name, iconClass: 'resource-colour-tile', colour } : { icon: name };
}

/**
 * An opener that hands the record to an action service's context panel,
 * falling back to its route transition. Resolved lazily from the engine
 * owner so descriptors can be built before the services exist.
 */
export function panelOpener(owner, serviceName, { mode = 'panel' } = {}) {
    return (record) => {
        const service = owner.lookup(`service:${serviceName}`);

        if (!service) {
            return false;
        }

        const view =
            (mode === 'panel' ? (service.panel?.view ?? service.transition?.view) : null) ??
            (mode === 'transition' ? service.transition?.view : null) ??
            (mode === 'modal' ? service.modal?.view : null);

        if (typeof view !== 'function') {
            return false;
        }

        view.call(service, record);

        return true;
    };
}

/** An opener that transitions to a route inside the engine. */
export function routeOpener(owner, routeName) {
    return (record) => {
        const router = owner.lookup('service:router');

        if (!router || !record) {
            return false;
        }

        const mountPrefix = owner.lookup('service:vehicle-actions')?.mountPrefix ?? 'console.fleet-ops';

        router.transitionTo(`${mountPrefix}.${routeName}`, record);

        return true;
    };
}

/** An opener that delegates to a parent record. */
export function parentOpener(owner, parentRelation, parentType) {
    return async (record, context) => {
        const registry = owner.lookup('service:resource-registry');
        const parent = relation(owner, record, parentRelation);

        if (!registry || !parent) {
            return false;
        }

        return registry.open(parent, { ...context, resourceType: parentType });
    };
}
