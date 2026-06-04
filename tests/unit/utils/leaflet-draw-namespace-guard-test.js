import { module, test } from 'qunit';
import ensureLeafletDrawEditNamespace from 'dummy/utils/leaflet-draw-namespace-guard';

module('Unit | Utility | leaflet-draw-namespace-guard', function (hooks) {
    hooks.beforeEach(function () {
        this.originalL = window.L;
        this.originalLeaflet = window.leaflet;
    });

    hooks.afterEach(function () {
        window.L = this.originalL;
        window.leaflet = this.originalLeaflet;
    });

    test('it guards window.L', function (assert) {
        window.L = {};
        window.leaflet = undefined;

        const guarded = ensureLeafletDrawEditNamespace();

        assert.strictEqual(guarded.length, 1);
        assert.deepEqual(window.L.Edit, {});
    });

    test('it guards window.leaflet', function (assert) {
        window.L = undefined;
        window.leaflet = {};

        const guarded = ensureLeafletDrawEditNamespace();

        assert.strictEqual(guarded.length, 1);
        assert.deepEqual(window.leaflet.Edit, {});
    });

    test('it guards distinct window.leaflet and window.L objects', function (assert) {
        window.leaflet = {};
        window.L = {};

        const guarded = ensureLeafletDrawEditNamespace();

        assert.strictEqual(guarded.length, 2);
        assert.deepEqual(window.leaflet.Edit, {});
        assert.deepEqual(window.L.Edit, {});
    });

    test('it preserves an existing edit marker handler', function (assert) {
        const markerHandler = function MarkerHandler() {};
        window.L = { Edit: { Marker: markerHandler } };
        window.leaflet = undefined;

        ensureLeafletDrawEditNamespace();

        assert.strictEqual(window.L.Edit.Marker, markerHandler);
    });
});
