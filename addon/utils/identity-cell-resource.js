import { get } from '@ember/object';

export function resolveIdentityCellResource(args) {
    const column = args.column ?? {};
    const resourcePath = column.resourcePath;

    if (typeof resourcePath === 'function') {
        return resourcePath(args.row, args.value, column) ?? null;
    }

    if (typeof resourcePath === 'string') {
        return get(args.row, resourcePath) ?? null;
    }

    if (args.value && typeof args.value === 'object') {
        return args.value;
    }

    return args.row;
}

const inflight = new WeakMap();

/**
 * A stand-in for a related record a row only knows by name: enough for an
 * identity cell to render (`name`, `resourceType`) and a `loadResource()`
 * the cell, pill or summary calls when the real record is needed. The
 * stub never carries a UUID in an identifier field, so nothing renders one
 * as a plate or an id, and concurrent loads of the same row share one
 * request.
 *
 * `load` receives the row and returns the record (or a promise of it); with
 * no `load`, `loadResource()` resolves to null and the stub stays static.
 */
export function buildIdentityStub(row, { type, nameKey = `${type}_name`, name, load, extra = {} } = {}) {
    const label = name ?? (row ? get(row, nameKey) : null);

    if (!label) {
        return null;
    }

    const stub = {
        ...extra,
        name: label,
        display_name: label,
        displayName: label,
        resourceType: type,
        isIdentityStub: true,
        loadResource: async () => {
            if (typeof load !== 'function') {
                return null;
            }

            let loads = inflight.get(row);

            if (!loads) {
                loads = new Map();
                inflight.set(row, loads);
            }

            if (!loads.has(type)) {
                loads.set(
                    type,
                    Promise.resolve()
                        .then(() => load(row))
                        .catch(() => null)
                        .finally(() => loads.delete(type))
                );
            }

            return loads.get(type);
        },
    };

    return stub;
}
