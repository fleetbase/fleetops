const L = window.leaflet || window.L;

export function findLayer(map, findCallback) {
    const layers = [];

    map.eachLayer((layer) => {
        layers.push(layer);
    });

    if (typeof findCallback === 'function') {
        return layers.find(findCallback);
    }

    return null;
}

export function getLayerById(map, layerId) {
    let targetLayer = null;

    map.eachLayer((layer) => {
        // Check if the layer has an ID property
        if (layer.options && layer.options.id === layerId) {
            targetLayer = layer;
        }
    });

    return targetLayer;
}

export function flyToLayer(map, layer, zoom, options = {}) {
    if (!map || !layer) return;

    let targetLatLng = layer instanceof L.Marker ? layer.getLatLng() : layer.getCenter ? layer.getCenter() : layer.getBounds ? layer.getBounds().getCenter() : null;
    if (!targetLatLng) return;

    const dur = options.duration ?? 1.25;

    // If any padding is requested, use flyToBounds on a zero-area bounds
    if (options.padding || options.paddingTopLeft || options.paddingBottomRight) {
        const bounds = L.latLngBounds(targetLatLng, targetLatLng);
        map.flyToBounds(bounds, {
            padding: options.padding,
            paddingTopLeft: options.paddingTopLeft,
            paddingBottomRight: options.paddingBottomRight,
            maxZoom: zoom ?? map.getZoom(),
            animate: true,
            duration: dur,
        });
    } else {
        map.flyTo(targetLatLng, zoom, { duration: dur });
    }

    if (typeof options.moveend === 'function') {
        map.once('moveend', () => options.moveend(layer));
    }
}
