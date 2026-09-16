import formatDate from '@fleetbase/ember-ui/utils/format-date';
import { get } from '@ember/object';
import { getPlaceholderImage, resolveResourceImage } from '../placeholder-images';

/**
 * Small building blocks the FleetOps descriptors share. They are written
 * against attribute names, so an identity stub or an API payload renders
 * the same as an Ember Data record.
 */

export function present(value) {
    return value !== undefined && value !== null && String(value).trim() !== '';
}

/** The first present value at any of the paths. */
export function first(record, ...paths) {
    if (!record) {
        return null;
    }

    for (const path of paths) {
        const value = get(record, path);

        if (present(value)) {
            return value;
        }
    }

    return null;
}

/** A related record read through its reference, never a promise proxy. */
export function relation(owner, record, name) {
    const registry = owner?.lookup?.('service:resource-registry');

    if (registry && typeof registry.relationValue === 'function') {
        return registry.relationValue(record, name);
    }

    const value = record ? get(record, name) : null;

    if (value && typeof value === 'object' && typeof value.then === 'function') {
        return value.content ?? null;
    }

    return value ?? null;
}

/** A photo with the styled placeholder as fallback. */
export function photo(record, type, path = 'photo_url') {
    return { url: resolveResourceImage(get(record, path), type), fallback: getPlaceholderImage(type), shape: type === 'vehicle' || type === 'trailer' ? 'square' : 'round' };
}

export function icon(name, iconClass) {
    return iconClass ? { icon: name, iconClass } : { icon: name };
}

/** A coloured tile for records that carry their own colour (service areas, zones). */
export function colourTile(record, name) {
    const colour = first(record, 'color', 'stroke_color');

    return colour ? { icon: name, iconClass: 'resource-colour-tile', colour } : { icon: name };
}

export function badge(key, iconName, label, extra = {}) {
    return present(label) ? { key, icon: iconName, label, ...extra } : null;
}

export function badges(...list) {
    return list.filter(Boolean);
}

export function fact(label, value, extra = {}) {
    return { labelKey: `resource-summary.facts.${label}`, value, ...extra };
}

/** A fact that renders as the related record's pill when the record is loaded. */
export function relatedFact(label, related, relatedType, fallbackValue) {
    return { labelKey: `resource-summary.facts.${label}`, related: related ?? null, relatedType, value: fallbackValue ?? null };
}

/**
 * A date the way the console writes one everywhere else (date-fns, not the
 * browser locale), or null for anything that is not a date.
 */
export function dateLabel(value, pattern = 'dd MMM yyyy, HH:mm') {
    if (!present(value)) {
        return null;
    }

    const date = value instanceof Date ? value : new Date(value);

    return Number.isNaN(date.getTime()) ? null : formatDate(date, pattern);
}

export function money(amount, currency) {
    if (!present(amount)) {
        return null;
    }

    return present(currency) ? `${amount} ${currency}` : String(amount);
}

export function join(parts, separator = ' ') {
    return parts.filter(present).join(separator) || null;
}

/** The resolved `*_type` for a polymorphic relation, from the record or a `_type` attribute. */
export function polymorphicType(record, relationName, typeAttr) {
    const related = record ? get(record, relationName) : null;
    const modelName = related?.constructor?.modelName ?? related?.content?.constructor?.modelName;

    return modelName ?? (typeAttr ? get(record, typeAttr) : null) ?? null;
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
