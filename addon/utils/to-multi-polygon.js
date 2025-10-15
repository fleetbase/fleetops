import { Polygon, Circle, MultiPolygon, Feature } from '@fleetbase/fleetops-data/utils/geojson';

/** unwrap input to { geom, props, id, bbox, wasFeature } */
function unwrap(input) {
    if (!input) throw new Error('toMultiPolygon: missing input');

    // Feature instance or plain GeoJSON Feature
    if (input instanceof Feature || input?.type === 'Feature') {
        return {
            geom: input.geometry,
            props: input.properties ?? {},
            id: input.id,
            bbox: input.bbox,
            wasFeature: true,
        };
    }

    // Geometry-like object (Polygon/MultiPolygon/Circle) or their instances
    if (input?.type || input instanceof Polygon || input instanceof MultiPolygon || input instanceof Circle) {
        const geom = input.geometry ?? input; // classes may carry .geometry or be geometry themselves
        return { geom, props: {}, id: undefined, bbox: undefined, wasFeature: false };
    }

    throw new Error('toMultiPolygon: unsupported input');
}

/**
 * Convert Polygon / Circle / MultiPolygon into a MultiPolygon.
 * - Polygon  -> MultiPolygon ([coords])
 * - Circle   -> MultiPolygon ([coords])  // your Circle already polygonizes
 * - MultiPolygon -> MultiPolygon (no-op)
 * If `asFeature` is true OR input was a Feature, return a Feature wrapping the MultiPolygon.
 */
export default function toMultiPolygon(input, { asFeature = false } = {}) {
    const { geom, props, id, bbox, wasFeature } = unwrap(input);

    // Circle: your implementation already sets .geometry to a Polygon
    if (input instanceof Circle || geom?.type === 'Circle') {
        // if someone passed raw Circle geometry (unlikely), reconstruct to get Polygon
        const circle = input instanceof Circle ? input : new Circle(geom.properties?.center, geom.properties?.radius, geom.properties?.steps);
        const coords = circle.geometry?.coordinates; // polygon coords
        const mp = new MultiPolygon([coords]);

        // return asFeature || wasFeature ? new Feature({ type: 'MultiPolygon', coordinates: mp.coordinates, properties: props, id, bbox }) : mp;
        return mp;
    }

    if (geom?.type === 'Polygon' || input instanceof Polygon) {
        const coords = geom.coordinates ?? input.coordinates;
        const mp = new MultiPolygon([coords]);

        // return asFeature || wasFeature ? new Feature({ type: 'MultiPolygon', coordinates: mp.coordinates, properties: props, id, bbox }) : mp;
        return mp;
    }

    if (geom?.type === 'MultiPolygon' || input instanceof MultiPolygon) {
        // already a MultiPolygon — preserve Feature wrapper if applicable
        return asFeature || wasFeature ? new Feature({ type: 'MultiPolygon', coordinates: geom.coordinates, properties: props, id, bbox }) : input.geometry ? input.geometry : input;
    }

    throw new Error(`toMultiPolygon: unsupported geometry type "${geom?.type}"`);
}
