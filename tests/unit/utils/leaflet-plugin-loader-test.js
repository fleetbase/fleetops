import { module, test } from 'qunit';
import ensureLeafletPluginsReady, { resetLeafletPluginLoaderForTesting } from 'dummy/utils/leaflet-plugin-loader';

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

function findScript(src) {
    return Array.from(document.scripts).find((script) => script.getAttribute('src') === src);
}

module('Unit | Utility | leaflet-plugin-loader', function (hooks) {
    hooks.beforeEach(function () {
        this.originalL = window.L;
        this.originalLeaflet = window.leaflet;
        this.originalFleetopsLeafletPluginsLoaded = window.fleetopsLeafletPluginsLoaded;
        this.basePath = 'test-leaflet';
        window.L = {};
        window.leaflet = undefined;
        resetLeafletPluginLoaderForTesting();
    });

    hooks.afterEach(function () {
        Array.from(document.querySelectorAll('[data-fleetops-leaflet-plugin="true"], [data-fleetops-leaflet-plugin-stylesheet="true"]')).forEach((element) => element.remove());
        window.L = this.originalL;
        window.leaflet = this.originalLeaflet;
        window.fleetopsLeafletPluginsLoaded = this.originalFleetopsLeafletPluginsLoaded;
        resetLeafletPluginLoaderForTesting();
    });

    test('it resolves immediately when Leaflet plugins are already present', async function (assert) {
        markLeafletPluginsReady(window.L);

        const L = await ensureLeafletPluginsReady({ basePath: this.basePath });

        assert.strictEqual(L, window.L);
        assert.strictEqual(window.leaflet, window.L);
        assert.strictEqual(document.querySelectorAll('[data-fleetops-leaflet-plugin="true"]').length, 0);
        assert.true(window.fleetopsLeafletPluginsLoaded);
    });

    test('it loads scripts once and waits for script load events before resolving', async function (assert) {
        assert.expect(6);

        const promiseA = ensureLeafletPluginsReady({ basePath: this.basePath, timeoutMs: 1000 });
        const promiseB = ensureLeafletPluginsReady({ basePath: this.basePath, timeoutMs: 1000 });

        assert.strictEqual(promiseA, promiseB);

        const contextmenuScript = findScript('/test-leaflet/leaflet.contextmenu.js');
        assert.ok(contextmenuScript);
        assert.strictEqual(document.querySelectorAll('script[src="/test-leaflet/leaflet.contextmenu.js"]').length, 1);

        window.L.Map = { ContextMenu: function ContextMenu() {} };
        contextmenuScript.dispatchEvent(new Event('load'));
        await Promise.resolve();

        const drawScript = findScript('/test-leaflet/leaflet.draw-src.js');
        assert.ok(drawScript);
        assert.strictEqual(document.querySelectorAll('script[src="/test-leaflet/leaflet.draw-src.js"]').length, 1);

        markLeafletPluginsReady(window.L);
        drawScript.dispatchEvent(new Event('load'));

        const L = await promiseA;
        assert.strictEqual(L.Edit.Marker.name, 'MarkerEdit');
    });

    test('it rejects when a plugin script fails to load', async function (assert) {
        const promise = ensureLeafletPluginsReady({ basePath: this.basePath, timeoutMs: 1000 });
        const contextmenuScript = findScript('/test-leaflet/leaflet.contextmenu.js');

        contextmenuScript.dispatchEvent(new Event('error'));

        await assert.rejects(promise, /Failed to load/);
        assert.false(window.fleetopsLeafletPluginsLoaded);
    });
});
