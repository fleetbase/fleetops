import { module, test } from 'qunit';
import { findLayer, getLayerById, flyToLayer } from '@fleetbase/fleetops-engine/utils/leaflet';

const L = window.leaflet || window.L;

function fakeMap(layers = []) {
    const calls = [];

    return {
        calls,
        eachLayer(callback) {
            layers.forEach(callback);
        },
        flyTo(...args) {
            calls.push(['flyTo', ...args]);
        },
        flyToBounds(...args) {
            calls.push(['flyToBounds', ...args]);
        },
        getZoom() {
            return 9;
        },
        once(event, callback) {
            calls.push(['once', event]);
            callback();
        },
    };
}

module('Unit | Utility | leaflet', function () {
    test('findLayer returns the first layer matching the callback', function (assert) {
        const a = { name: 'a' };
        const b = { name: 'b' };
        const map = fakeMap([a, b]);

        assert.strictEqual(
            findLayer(map, (layer) => layer.name === 'b'),
            b
        );
        assert.strictEqual(
            findLayer(map, () => false),
            undefined
        );
        assert.strictEqual(findLayer(map), null, 'without a callback nothing is searched');
    });

    test('getLayerById matches on the layer option id', function (assert) {
        const target = { options: { id: 'zone_1' } };
        const map = fakeMap([{}, { options: {} }, target]);

        assert.strictEqual(getLayerById(map, 'zone_1'), target);
        assert.strictEqual(getLayerById(map, 'zone_2'), null);
    });

    test('flyToLayer ignores a missing map, layer or target position', function (assert) {
        const map = fakeMap();

        flyToLayer(null, {});
        flyToLayer(map, null);
        flyToLayer(map, {}, 5);

        assert.deepEqual(map.calls, []);
    });

    test('flyToLayer flies to a marker, a centered layer or a bounded layer', function (assert) {
        const map = fakeMap();

        flyToLayer(map, L.marker([1, 2]), 5);
        flyToLayer(map, { getCenter: () => L.latLng(3, 4) }, 6, { duration: 2 });
        flyToLayer(map, { getBounds: () => L.latLngBounds([0, 0], [2, 2]) }, 7);

        assert.deepEqual(
            map.calls.map(([name, latlng, zoom, options]) => [name, latlng.lat, latlng.lng, zoom, options]),
            [
                ['flyTo', 1, 2, 5, { duration: 1.25 }],
                ['flyTo', 3, 4, 6, { duration: 2 }],
                ['flyTo', 1, 1, 7, { duration: 1.25 }],
            ]
        );
    });

    test('flyToLayer uses zero-area bounds when any padding is requested', function (assert) {
        const map = fakeMap();
        const layer = L.marker([1, 2]);

        flyToLayer(map, layer, undefined, { padding: [10, 10] });
        flyToLayer(map, layer, 4, { paddingTopLeft: [1, 1] });
        flyToLayer(map, layer, 3, { paddingBottomRight: [2, 2], duration: 0.5 });

        assert.deepEqual(
            map.calls.map(([name, bounds, options]) => [name, bounds.getCenter().lat, bounds.getCenter().lng, options]),
            [
                ['flyToBounds', 1, 2, { padding: [10, 10], paddingTopLeft: undefined, paddingBottomRight: undefined, maxZoom: 9, animate: true, duration: 1.25 }],
                ['flyToBounds', 1, 2, { padding: undefined, paddingTopLeft: [1, 1], paddingBottomRight: undefined, maxZoom: 4, animate: true, duration: 1.25 }],
                ['flyToBounds', 1, 2, { padding: undefined, paddingTopLeft: undefined, paddingBottomRight: [2, 2], maxZoom: 3, animate: true, duration: 0.5 }],
            ]
        );
    });

    test('flyToLayer hands the layer to the moveend callback once the move ends', function (assert) {
        const map = fakeMap();
        const layer = L.marker([1, 2]);
        const moved = [];

        flyToLayer(map, layer, 5, { moveend: (target) => moved.push(target) });

        assert.deepEqual(
            map.calls.map(([name]) => name),
            ['flyTo', 'once']
        );
        assert.strictEqual(map.calls[1][1], 'moveend');
        assert.deepEqual(moved, [layer]);
    });

    test('flyToLayer resolves Leaflet from either global', function (assert) {
        const map = fakeMap();
        const original = window.leaflet;
        window.leaflet = undefined;

        try {
            flyToLayer(map, L.marker([1, 2]), 5);
        } finally {
            window.leaflet = original;
        }

        assert.strictEqual(map.calls[0][0], 'flyTo', 'window.L alone is enough to recognise a marker');
    });
});
