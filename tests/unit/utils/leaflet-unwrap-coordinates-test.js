import { module, test } from 'qunit';
import unwrapCoordinates, { latLngToCRS, unwrapCoordinates as namedUnwrapCoordinates } from '@fleetbase/fleetops-engine/utils/leaflet-unwrap-coordinates';

const L = window.leaflet || window.L;

function rounded(latLng) {
    return [Number(latLng.lat.toFixed(6)), Number(latLng.lng.toFixed(6))];
}

module('Unit | Utility | leaflet-unwrap-coordinates', function () {
    test('latLngToCRS round-trips a position through the projection', function (assert) {
        const point = latLngToCRS(26, -80);

        assert.ok(point instanceof L.LatLng);
        assert.deepEqual(rounded(point), [26, -80]);
        assert.deepEqual(rounded(latLngToCRS(26, -80, L.CRS.EPSG4326)), [26, -80], 'an explicit CRS is honoured');
    });

    test('it converts a single GeoJSON position, a ring and nested rings', function (assert) {
        assert.deepEqual(rounded(unwrapCoordinates([200, 45])), [45, -160], 'longitude is wrapped before projecting');

        const ring = unwrapCoordinates([
            [-80, 26],
            [-80.1, 26.1],
        ]);
        assert.deepEqual(ring.map(rounded), [
            [26, -80],
            [26.1, -80.1],
        ]);

        const polygon = unwrapCoordinates([
            [
                [-80, 26],
                [-80.1, 26.1],
                [-80, 26],
            ],
        ]);
        assert.deepEqual(
            polygon.map((r) => r.map(rounded)),
            [
                [
                    [26, -80],
                    [26.1, -80.1],
                    [26, -80],
                ],
            ]
        );
    });

    test('non-array input passes straight through and both exports are the same function', function (assert) {
        assert.strictEqual(unwrapCoordinates('nope'), 'nope');
        assert.strictEqual(unwrapCoordinates(undefined), undefined);
        assert.strictEqual(namedUnwrapCoordinates, unwrapCoordinates);
    });
});
