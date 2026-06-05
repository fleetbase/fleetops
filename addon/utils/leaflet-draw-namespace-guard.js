export function ensureLeafletDrawEditNamespace(leaflet = null) {
    const leaflets = [];

    if (leaflet) {
        leaflets.push(leaflet);
    }

    if (typeof window !== 'undefined') {
        leaflets.push(window.leaflet, window.L);
    }

    const guarded = [];
    for (const L of leaflets) {
        if (!L || guarded.includes(L)) continue;
        L.Edit = L.Edit || {};
        guarded.push(L);
    }

    return guarded;
}

export default ensureLeafletDrawEditNamespace;
