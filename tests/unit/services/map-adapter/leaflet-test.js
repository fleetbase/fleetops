import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { resetLeafletPluginLoaderForTesting } from 'dummy/utils/leaflet-plugin-loader';

function markLeafletPluginsReady(L) {
    L.Edit = {
        ...(L.Edit ?? {}),
        Marker: function MarkerEdit() {},
        Poly: function PolyEdit() {},
    };
    L.Control = {
        ...(L.Control ?? {}),
        Draw: function DrawControl() {},
    };
    L.Map = {
        ...(L.Map ?? {}),
        ContextMenu: function ContextMenu() {},
    };
}

module('Unit | Service | map-adapter/leaflet', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.originalL = window.L;
        this.originalLeaflet = window.leaflet;
        window.L = window.L || {};
        window.leaflet = window.L;
        resetLeafletPluginLoaderForTesting();
    });

    hooks.afterEach(function () {
        window.L = this.originalL;
        window.leaflet = this.originalLeaflet;
        resetLeafletPluginLoaderForTesting();
    });

    test('ensureInteractive waits for Leaflet plugin readiness and returns the map', async function (assert) {
        const service = this.owner.lookup('service:map-adapter/leaflet');
        const map = { id: 'leaflet-map' };

        markLeafletPluginsReady(window.L);

        const result = await service.ensureInteractive({ map, timeoutMs: 1000 });

        assert.strictEqual(result, map);
        assert.true(window.fleetopsLeafletPluginsLoaded);
    });
});
