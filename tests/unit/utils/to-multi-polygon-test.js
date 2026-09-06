import { module, test } from 'qunit';
import toMultiPolygon from '@fleetbase/fleetops-engine/utils/to-multi-polygon';
import { Polygon, Circle, MultiPolygon, Feature } from '@fleetbase/fleetops-data/utils/geojson';

const square = [
    [
        [0, 0],
        [0, 1],
        [1, 1],
        [1, 0],
        [0, 0],
    ],
];

module('Unit | Utility | to-multi-polygon', function () {
    test('a polygon, as an instance, plain geometry or feature, becomes a single-polygon MultiPolygon', function (assert) {
        for (const input of [
            new Polygon(square),
            { type: 'Polygon', coordinates: square },
            { type: 'Feature', geometry: { type: 'Polygon', coordinates: square }, properties: { name: 'sq' } },
            { type: 'Feature', geometry: { type: 'Polygon', coordinates: square } },
            new Feature({ type: 'Feature', geometry: { type: 'Polygon', coordinates: square }, properties: {} }),
        ]) {
            const result = toMultiPolygon(input);

            assert.ok(result instanceof MultiPolygon);
            assert.deepEqual(result.coordinates, [square]);
        }
    });

    test('a circle is polygonized from its instance or rebuilt from raw circle geometry', function (assert) {
        const circle = new Circle([1, 2], 100, 8);
        const fromInstance = toMultiPolygon(circle);

        assert.ok(fromInstance instanceof MultiPolygon);
        assert.deepEqual(fromInstance.coordinates, [circle.geometry.coordinates]);

        const fromRaw = toMultiPolygon({ type: 'Circle', properties: { center: [1, 2], radius: 100, steps: 8 } });
        assert.deepEqual(fromRaw.coordinates, fromInstance.coordinates);

        assert.throws(() => toMultiPolygon({ type: 'Circle' }), /missing parameter/, 'raw circle geometry needs its center and radius');
    });

    test('a MultiPolygon passes through unless a feature wrapper is wanted', function (assert) {
        const instance = new MultiPolygon([square]);
        const plain = { type: 'MultiPolygon', coordinates: [square] };

        assert.strictEqual(toMultiPolygon(instance), instance);
        assert.strictEqual(toMultiPolygon(plain, {}), plain);

        const wrapped = toMultiPolygon(plain, { asFeature: true });
        assert.ok(wrapped instanceof Feature);
        assert.strictEqual(wrapped.geometry.type, 'MultiPolygon');
        assert.deepEqual(wrapped.geometry.coordinates, [square]);

        const feature = { type: 'Feature', id: 'f1', bbox: [0, 0, 1, 1], geometry: plain, properties: { name: 'multi' } };
        const rewrapped = toMultiPolygon(feature);
        assert.ok(rewrapped instanceof Feature);
        assert.deepEqual(rewrapped.geometry.properties, { name: 'multi' });
        assert.strictEqual(rewrapped.geometry.id, 'f1');
        assert.deepEqual(rewrapped.geometry.bbox, [0, 0, 1, 1]);

        const embedded = toMultiPolygon({ type: 'Wrapper', geometry: plain });
        assert.strictEqual(embedded, plain, 'a geometry-like wrapper hands back its embedded MultiPolygon');

        const geometryFeature = toMultiPolygon({ type: 'Feature', geometry: plain });
        assert.deepEqual(geometryFeature.geometry.properties, {}, 'missing feature properties default to an empty object');
    });

    test('it rejects missing, unknown and unsupported input', function (assert) {
        assert.throws(() => toMultiPolygon(), /missing input/);
        assert.throws(() => toMultiPolygon({}), /unsupported input/);
        assert.throws(() => toMultiPolygon(42), /unsupported input/);
        assert.throws(() => toMultiPolygon({ type: 'Point', coordinates: [1, 2] }), /unsupported geometry type "Point"/);
    });
});
