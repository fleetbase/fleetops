import { module, test } from 'qunit';
import {
    toPos,
    closeRing,
    ringsFromLatLngs,
    getGeoJsonFeature,
    createGeoJsonPolygon,
    createGeoJsonMultiPolygon,
    createGeoJsonCircle,
    createGeoJsonFromLayer,
    createFeatureCollectionFromLayers,
} from '@fleetbase/fleetops-engine/utils/leaflet-to-geojson';

const ring = [
    { lat: 1, lng: 10 },
    { lat: 2, lng: 20 },
    { lat: 3, lng: 30 },
];
const closed = [
    [10, 1],
    [20, 2],
    [30, 3],
    [10, 1],
];

module('Unit | Utility | leaflet-to-geojson', function () {
    test('toPos and closeRing convert Leaflet positions into closed GeoJSON rings', function (assert) {
        assert.deepEqual(toPos({ lat: 1, lng: 2 }), [2, 1]);
        assert.deepEqual(toPos([1, 2]), [2, 1], 'array positions are lat, lng pairs');

        assert.deepEqual(closeRing([]), []);
        assert.deepEqual(
            closeRing([
                [1, 1],
                [2, 2],
            ]),
            [
                [1, 1],
                [2, 2],
                [1, 1],
            ]
        );
        assert.deepEqual(closeRing(closed), closed, 'an already closed ring is left alone');
    });

    test('ringsFromLatLngs accepts a flat ring, an array of rings or nothing', function (assert) {
        assert.deepEqual(ringsFromLatLngs(ring), [closed]);
        assert.deepEqual(ringsFromLatLngs([ring, ring]), [closed, closed]);
        assert.deepEqual(ringsFromLatLngs(null), []);
        assert.deepEqual(ringsFromLatLngs([]), [[]], 'an empty ring is one empty closed ring');
    });

    test('getGeoJsonFeature normalizes Leaflet toGeoJSON output to a Feature', function (assert) {
        const feature = { type: 'Feature', geometry: { type: 'Point', coordinates: [1, 2] }, properties: {} };
        const geometry = { type: 'Point', coordinates: [1, 2] };

        assert.strictEqual(getGeoJsonFeature(null), null);
        assert.strictEqual(getGeoJsonFeature(feature), feature);
        assert.strictEqual(getGeoJsonFeature({ type: 'FeatureCollection', features: [feature] }), feature);
        assert.strictEqual(getGeoJsonFeature({ type: 'FeatureCollection', features: [] }), null, 'an empty collection has no feature');
        assert.deepEqual(getGeoJsonFeature(geometry), { type: 'Feature', geometry, properties: {} });
        assert.strictEqual(getGeoJsonFeature({ type: 'Mystery' }), null);
    });

    test('createGeoJsonPolygon and createGeoJsonMultiPolygon read the layer positions', function (assert) {
        const polygon = createGeoJsonPolygon({ getLatLngs: () => ring });
        assert.strictEqual(polygon.type, 'Polygon');
        assert.deepEqual(polygon.coordinates, [closed]);

        const wrapped = createGeoJsonPolygon({ getLatLngs: () => [{ lat: 1, lng: 190 }] }, { properties: {} });
        assert.deepEqual(wrapped.coordinates, [[[-170, 1]]], 'longitudes are wrapped');

        assert.strictEqual(createGeoJsonPolygon({}), null, 'a layer without positions has no polygon');
        assert.strictEqual(createGeoJsonPolygon({ getLatLngs: () => [] }), null);

        const multi = createGeoJsonMultiPolygon({ getLatLngs: () => [ring, [ring]] });
        assert.strictEqual(multi.type, 'MultiPolygon');
        assert.deepEqual(multi.coordinates, [[closed], [closed]]);
        assert.strictEqual(createGeoJsonMultiPolygon({}, { properties: {} }), null);
        assert.strictEqual(createGeoJsonMultiPolygon({ getLatLngs: () => [] }), null);
    });

    test('createGeoJsonCircle polygonizes the layer center and radius', function (assert) {
        const circle = createGeoJsonCircle({ getLatLng: () => ({ lat: 1, lng: 2 }), getRadius: () => 500 });
        assert.deepEqual(circle.properties, { radius: 500, center: [2, 1], steps: 64 });
        assert.strictEqual(circle.geometry.type, 'Polygon');

        const coarse = createGeoJsonCircle({ getLatLng: () => ({ lat: 1, lng: 2 }), getRadius: () => 500 }, { steps: 8 });
        assert.strictEqual(coarse.properties.steps, 8);

        const defaulted = createGeoJsonCircle({ getLatLng: () => ({ lat: 1, lng: 2 }) });
        assert.strictEqual(defaulted.properties.radius, 250, 'a layer without a radius takes the GeoJSON default');
    });

    test('createGeoJsonFromLayer picks the builder by layer type and falls back to Leaflet GeoJSON', function (assert) {
        const circleLayer = { getLatLng: () => ({ lat: 1, lng: 2 }), getRadius: () => 10 };
        const polygonLayer = { getLatLngs: () => ring };

        assert.strictEqual(createGeoJsonFromLayer(circleLayer, { layerType: 'circle' }).properties.radius, 10);
        assert.strictEqual(createGeoJsonFromLayer(circleLayer, { layerType: 'circlemarker' }).properties.radius, 10);
        assert.strictEqual(createGeoJsonFromLayer(polygonLayer, { layerType: 'polygon' }).type, 'Polygon');
        assert.strictEqual(createGeoJsonFromLayer(polygonLayer, { layerType: 'rectangle' }).type, 'Polygon');

        const fromPolygon = createGeoJsonFromLayer({ toGeoJSON: () => ({ type: 'Feature', geometry: { type: 'Polygon', coordinates: [closed] } }) });
        assert.strictEqual(fromPolygon.type, 'Polygon');
        assert.deepEqual(fromPolygon.coordinates, [closed]);

        const fromMulti = createGeoJsonFromLayer({ toGeoJSON: () => ({ type: 'MultiPolygon', coordinates: [[closed]] }) }, {});
        assert.strictEqual(fromMulti.type, 'MultiPolygon');

        const fromPoint = createGeoJsonFromLayer({ toGeoJSON: () => ({ type: 'Feature', geometry: { type: 'Point', coordinates: [1, 2] }, properties: {} }) });
        assert.strictEqual(fromPoint.type, 'Feature');
        assert.deepEqual(fromPoint.geometry, { type: 'Point', coordinates: [1, 2] });

        assert.strictEqual(createGeoJsonFromLayer({}), null, 'a layer that cannot serialize itself yields nothing');
        assert.strictEqual(createGeoJsonFromLayer({ toGeoJSON: () => ({ type: 'Feature' }) }).type, 'Feature', 'a feature without geometry is still wrapped');
    });

    test('createFeatureCollectionFromLayers collects every convertible layer', function (assert) {
        const polygonLayer = { getLatLngs: () => ring };

        assert.deepEqual(createFeatureCollectionFromLayers(null).features, []);

        const collection = createFeatureCollectionFromLayers([polygonLayer, {}], { layerType: 'polygon' });
        assert.strictEqual(collection.type, 'FeatureCollection');
        assert.strictEqual(collection.features.length, 1, 'layers that produce nothing are dropped');

        assert.strictEqual(createFeatureCollectionFromLayers(polygonLayer, { layerType: 'polygon' }).features.length, 1, 'a single layer needs no array');
    });
});
