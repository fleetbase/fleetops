import { get } from '@ember/object';

/**
 * Reads a relation without returning a promise proxy: the loaded record for
 * an Ember Data relationship (null while it is not loaded), the plain value
 * for a POJO. Async belongsTo relations return an always-truthy proxy from
 * `get`, which is why identity cells must read them this way.
 */
export default function relationValue(record, name) {
    if (!record || !name) {
        return null;
    }

    if (typeof record.belongsTo === 'function') {
        try {
            return record.belongsTo(name).value() ?? null;
        } catch {
            // not a belongsTo on this model
        }
    }

    if (typeof record.hasMany === 'function') {
        try {
            return record.hasMany(name).value() ?? null;
        } catch {
            // not a hasMany either
        }
    }

    const value = get(record, name);

    if (value && typeof value === 'object' && typeof value.then === 'function') {
        return value.content ?? null;
    }

    return value ?? null;
}
