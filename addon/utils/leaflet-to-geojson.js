/* eslint-disable no-unused-vars */
import { MultiPolygon, Polygon, Circle, Feature, FeatureCollection } from '@fleetbase/fleetops-data/utils/geojson';
import { isArray } from '@ember/array';
import wrapCoordinates from './leaflet-wrap-coordinates';

export function toPos(ll) {
    // Accept Leaflet LatLng or plain {lat,lng}
    return isArray(ll) ? [ll[1], ll[0]] : [ll.lng, ll.lat];
}

/** Ensure we always have an array-of-rings (each ring = LatLng[]) */
function normalizeToRings(latlngs) {
    if (!latlngs) return [];

    // Simple polygon given as [LatLng,...]
    if (isArray(latlngs) && !isArray(latlngs[0])) {
        return [latlngs];
    }

    // Already [ [LatLng,...], ... ]
    if (isArray(latlngs) && isArray(latlngs[0]) && !isArray(latlngs[0][0])) {
        return latlngs;
    }

    // Multipolygon case handled elsewhere
    return latlngs;
}

export function closeRing(coords) {
    if (!coords.length) return coords;
    const first = coords[0];
    const last = coords[coords.length - 1];
    if (first[0] !== last[0] || first[1] !== last[1]) {
        return [...coords, first];
    }
    return coords;
}

export function ringsFromLatLngs(latlngs) {
    const rings = normalizeToRings(latlngs);
    return rings.map((ring) => closeRing(ring.map(toPos)));
}

/** Normalize Leaflet’s toGeoJSON output to a Feature */
export function getGeoJsonFeature(geojson) {
    if (!geojson) return null;

    if (geojson.type === 'Feature') return geojson;

    if (geojson.type === 'FeatureCollection' && isArray(geojson.features) && geojson.features.length) {
        return geojson.features[0];
    }

    // Geometry -> Feature wrapper (safe fallback)
    if (geojson.type && geojson.coordinates) {
        return { type: 'Feature', geometry: geojson, properties: {} };
    }

    return null;
}

/** ---- Polygon ---- */
export function createGeoJsonPolygon(layer, { properties } = {}) {
    const latlngs = layer.getLatLngs?.();
    if (!latlngs || !latlngs.length) return null;

    const coordinates = wrapCoordinates(ringsFromLatLngs(latlngs));
    return new Polygon(coordinates);
}

/** ---- MultiPolygon ---- */
export function createGeoJsonMultiPolygon(layer, { properties } = {}) {
    // Leaflet MultiPolygon: [ [outer,hole,...], [outer,hole,...], ... ]
    const groups = layer.getLatLngs?.();
    if (!groups || !groups.length) return null;

    const multiCoords = groups.map((g) => ringsFromLatLngs(g));
    return new MultiPolygon(wrapCoordinates(multiCoords));
}

/** ---- Circle ---- */
export function createGeoJsonCircle(layer, { properties, steps = 64 } = {}) {
    const center = toPos(layer.getLatLng());
    const radius = layer.getRadius?.(); // meters

    return new Circle(center, radius, steps);
}

/** ---- Catch-all: pick helper by Leaflet layer type ---- */
export function createGeoJsonFromLayer(layer, options = {}) {
    const { layerType: type } = options;

    if (type === 'circle' || type === 'circlemarker') {
        return createGeoJsonCircle(layer, options);
    }

    // A rectangle is just a single Polygon (one outer ring)
    if (type === 'polygon' || type === 'rectangle') {
        return createGeoJsonPolygon(layer, options);
    }

    // Fallback to Leaflet’s own GeoJSON
    const feature = getGeoJsonFeature(layer.toGeoJSON?.());
    if (!feature) return null;

    switch (feature.geometry?.type) {
        case 'Polygon':
            return new Polygon(feature.geometry.coordinates);
        case 'MultiPolygon':
            return new MultiPolygon(feature.geometry.coordinates);
        default:
            return new Feature(feature);
    }
}

/** ---- Batching ---- */
export function createFeatureCollectionFromLayers(layers, options) {
    const features = []
        .concat(layers || [])
        .map((l) => createGeoJsonFromLayer(l, options))
        .filter(Boolean);

    return new FeatureCollection({ features });
}
