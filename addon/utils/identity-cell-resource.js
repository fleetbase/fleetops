import { get } from '@ember/object';

export { buildIdentityStub } from '@fleetbase/fleetops-data/utils/identity-stub';

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
