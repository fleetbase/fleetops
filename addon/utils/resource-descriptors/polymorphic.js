import { get } from '@ember/object';
import { first } from './helpers';

/**
 * The abstract polymorphic bases. A record of one of these model names
 * carries its concrete type in a `*_type` attribute (or is itself a
 * subtype model), so the descriptor resolves the concrete descriptor and
 * delegates to it. The subtype models themselves (attachable-vehicle,
 * facilitator-driver, …) are aliases on the concrete descriptors.
 */
const BASES = [
    { key: 'attachable', labelKey: 'resource.attachable', icon: 'link', typeAttrs: ['attachable_type', 'type'] },
    { key: 'customer', skip: true },
    { key: 'facilitator', labelKey: 'resource.facilitator', icon: 'handshake', typeAttrs: ['facilitator_type', 'type'] },
    { key: 'maintenance-subject', labelKey: 'resource.maintenance-subject', icon: 'truck', typeAttrs: ['subject_type', 'type'] },
];

function concreteKey(owner, record, typeAttrs) {
    const registry = owner.lookup('service:resource-registry');

    if (!registry || !record) {
        return null;
    }

    const modelName = record.constructor?.modelName;

    if (modelName) {
        const key = registry.resolveKey(modelName);

        if (key && !BASES.some((base) => base.key === key)) {
            return key;
        }
    }

    for (const attr of typeAttrs) {
        const key = registry.resolveKey(get(record, attr));

        if (key && !BASES.some((base) => base.key === key)) {
            return key;
        }
    }

    return null;
}

function delegate(owner, typeAttrs, field, fallback) {
    return (record, ...rest) => {
        const registry = owner.lookup('service:resource-registry');
        const key = concreteKey(owner, record, typeAttrs);
        const descriptor = key ? registry.getDescriptor(key) : null;
        const value = descriptor?.[field];

        if (typeof value === 'function') {
            return value(record, ...rest);
        }

        return typeof fallback === 'function' ? fallback(record, ...rest) : fallback;
    };
}

/**
 * The concrete record behind a polymorphic base record: an index payload
 * normalizes a facilitator as a bare `facilitator` model, which the concrete
 * resource's panel cannot use. A record that already is the concrete model or
 * one of its subtypes is returned as it is.
 */
export async function loadConcreteRecord(owner, descriptor, record) {
    const modelName = record?.constructor?.modelName;
    const known = [...(descriptor.modelNames ?? []), ...(descriptor.aliases ?? [])];
    const id = record ? (get(record, 'id') ?? get(record, 'uuid')) : null;
    const canonical = descriptor.modelNames?.[0];
    const store = owner.lookup('service:store');

    if (!modelName || known.includes(modelName) || !id || !canonical || !store) {
        return record;
    }

    try {
        return store.peekRecord(canonical, id) ?? (await store.findRecord(canonical, id)) ?? record;
    } catch {
        return record;
    }
}

export function resolveConcreteResourceKey(owner, record, typeAttrs = ['attachable_type', 'facilitator_type', 'subject_type', 'customer_type', 'maintainable_type', 'type']) {
    return concreteKey(owner, record, typeAttrs);
}

export default function buildPolymorphicDescriptors(owner) {
    return BASES.filter((base) => !base.skip).map((base) => ({
        key: base.key,
        labelKey: base.labelKey,
        icon: base.icon,
        modelNames: [base.key],
        polymorphicTypes: [`fleet-ops:${base.key}`],
        title: delegate(owner, base.typeAttrs, 'title', (record) => first(record, 'displayName', 'display_name', 'name', 'public_id')),
        identifier: delegate(owner, base.typeAttrs, 'identifier', null),
        image: delegate(owner, base.typeAttrs, 'image', () => ({ icon: base.icon })),
        online: delegate(owner, base.typeAttrs, 'online', undefined),
        status: delegate(owner, base.typeAttrs, 'status', (record) => first(record, 'status')),
        badges: delegate(owner, base.typeAttrs, 'badges', []),
        selectDetails: delegate(owner, base.typeAttrs, 'selectDetails', (record) => [first(record, 'type')]),
        facts: delegate(owner, base.typeAttrs, 'facts', []),
        canOpen: (record) => Boolean(concreteKey(owner, record, base.typeAttrs)),
        open: async (record, context) => {
            const registry = owner.lookup('service:resource-registry');
            const key = concreteKey(owner, record, base.typeAttrs);
            const descriptor = key ? registry.getDescriptor(key) : null;

            if (typeof descriptor?.open !== 'function') {
                return false;
            }

            // Open through the concrete descriptor directly. Handing the base
            // record back to `registry.open` resolves it to this descriptor
            // again, and the promise loop froze the page with no error.
            const concrete = await loadConcreteRecord(owner, descriptor, record);

            return descriptor.open(concrete, { ...context, resourceType: key });
        },
        components: { identity: `cell/${base.key}-identity` },
    }));
}
