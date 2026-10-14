import { module, test } from 'qunit';
import {
    COUNTRY_CENTER_ZOOM,
    DEFAULT_CENTER_ZOOM,
    companyCountryCenter,
    coordinatesOf,
    defaultMapCenter,
    isZoom,
    positionedBounds,
    toCoordinate,
} from '@fleetbase/fleetops-engine/utils/map-default-view';

module('Unit | Utility | map-default-view', function () {
    test('toCoordinate parses entered numbers and rejects blanks', function (assert) {
        assert.strictEqual(toCoordinate('-33.86'), -33.86);
        assert.strictEqual(toCoordinate(151.2), 151.2);
        assert.strictEqual(toCoordinate(0), 0);
        assert.strictEqual(toCoordinate(''), null);
        assert.strictEqual(toCoordinate('  '), null);
        assert.strictEqual(toCoordinate(null), null);
        assert.strictEqual(toCoordinate(undefined), null);
        assert.strictEqual(toCoordinate('north'), null);
    });

    test('isZoom accepts whole zoom levels from 1 to 20', function (assert) {
        assert.true(isZoom(1));
        assert.true(isZoom(20));
        assert.false(isZoom(0));
        assert.false(isZoom(21));
        assert.false(isZoom(4.5));
        assert.false(isZoom('12'));
    });

    test('defaultMapCenter needs both coordinates and defaults the zoom', function (assert) {
        assert.deepEqual(defaultMapCenter({ defaultMapLatitude: -33.86, defaultMapLongitude: 151.2, defaultMapZoom: 9 }), { latitude: -33.86, longitude: 151.2, zoom: 9 });
        assert.deepEqual(defaultMapCenter({ defaultMapLatitude: '-33.86', defaultMapLongitude: '151.2' }), { latitude: -33.86, longitude: 151.2, zoom: DEFAULT_CENTER_ZOOM });
        assert.deepEqual(defaultMapCenter({ defaultMapLatitude: 1, defaultMapLongitude: 2, defaultMapZoom: 99 }).zoom, DEFAULT_CENTER_ZOOM, 'an invalid zoom falls back');
        assert.strictEqual(defaultMapCenter({ defaultMapLatitude: -33.86 }), null, 'half a centre is no centre');
        assert.strictEqual(defaultMapCenter({ defaultMapLatitude: 95, defaultMapLongitude: 10 }), null);
        assert.strictEqual(defaultMapCenter({}), null);
        assert.strictEqual(defaultMapCenter(null), null);
        assert.strictEqual(defaultMapCenter(), null);
    });

    test('companyCountryCenter uses the server-derived country centre at country zoom', function (assert) {
        assert.deepEqual(companyCountryCenter({ companyMapCenter: { country: 'AU', latitude: -25.58, longitude: 134.5 } }), {
            latitude: -25.58,
            longitude: 134.5,
            zoom: COUNTRY_CENTER_ZOOM,
        });
        assert.strictEqual(companyCountryCenter({ companyMapCenter: null }), null);
        assert.strictEqual(companyCountryCenter({ companyMapCenter: { latitude: 'x', longitude: 1 } }), null);
        assert.strictEqual(companyCountryCenter(null), null);
        assert.strictEqual(companyCountryCenter(), null);
    });

    test('coordinatesOf reads GeoJSON points, pairs and objects and ignores Null Island', function (assert) {
        assert.deepEqual(coordinatesOf({ type: 'Point', coordinates: [151.2, -33.86] }), { latitude: -33.86, longitude: 151.2 });
        assert.deepEqual(coordinatesOf([-33.86, 151.2]), { latitude: -33.86, longitude: 151.2 });
        assert.deepEqual(coordinatesOf({ latitude: -33.86, longitude: 151.2 }), { latitude: -33.86, longitude: 151.2 });
        assert.strictEqual(coordinatesOf({ type: 'Point', coordinates: [0, 0] }), null);
        assert.strictEqual(coordinatesOf([null, null]), null);
        assert.strictEqual(coordinatesOf(null), null);
    });

    test('positionedBounds frames every positioned record', function (assert) {
        const bounds = positionedBounds([
            { location: { type: 'Point', coordinates: [151.2, -33.86] } },
            { location: { type: 'Point', coordinates: [115.86, -31.95] } },
            { location: { type: 'Point', coordinates: [0, 0] } },
            { location: null },
            null,
        ]);

        assert.deepEqual(bounds, [
            [-33.86, 115.86],
            [-31.95, 151.2],
        ]);
        assert.strictEqual(positionedBounds([{ location: null }]), null, 'no positioned vehicles');
        assert.strictEqual(positionedBounds([]), null);
        assert.strictEqual(positionedBounds(null), null);
        assert.strictEqual(positionedBounds(), null);
    });
});
