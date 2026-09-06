import { module, test } from 'qunit';
import leafletWrapCoordinates from '@fleetbase/fleetops-engine/utils/leaflet-wrap-coordinates';

module('Unit | Utility | leaflet-wrap-coordinates', function () {
    test('it wraps a single longitude into the canonical range and keeps extra dimensions', function (assert) {
        assert.deepEqual(leafletWrapCoordinates([200, 45]), [-160, 45]);
        assert.deepEqual(leafletWrapCoordinates([-190, 10, 300]), [170, 10, 300]);
        assert.deepEqual(leafletWrapCoordinates([180, 0]), [-180, 0], '180 folds onto -180');
        assert.deepEqual(leafletWrapCoordinates([-80, 26]), [-80, 26], 'in-range longitudes are untouched');
    });

    test('it recurses through rings and polygons', function (assert) {
        assert.deepEqual(
            leafletWrapCoordinates([
                [200, 45],
                [210, 46],
            ]),
            [
                [-160, 45],
                [-150, 46],
            ]
        );
        assert.deepEqual(leafletWrapCoordinates([[[[370, 1]]]]), [[[[10, 1]]]]);
    });

    test('it returns non-coordinate values as they are', function (assert) {
        assert.strictEqual(leafletWrapCoordinates('nope'), 'nope');
        assert.strictEqual(leafletWrapCoordinates(null), null);
        assert.deepEqual(leafletWrapCoordinates([200]), [200], 'a lone number is not a coordinate pair');
        assert.deepEqual(leafletWrapCoordinates([]), []);
    });
});
