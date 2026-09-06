import { module, test } from 'qunit';
import { waitUntil } from '@ember/test-helpers';
import ensureLeafletPluginsReady, { hasLeafletPluginsReady, resetLeafletPluginLoaderForTesting } from '@fleetbase/fleetops-engine/utils/leaflet-plugin-loader';

const CONTEXTMENU = '/test-leaflet/leaflet.contextmenu.js';
const DRAW = '/test-leaflet/leaflet.draw-src.js';

function markDrawReady(L) {
    L.Edit = { ...(L.Edit ?? {}), Marker: function MarkerEdit() {}, Poly: function PolyEdit() {} };
    L.Control = { ...(L.Control ?? {}), Draw: function DrawControl() {} };
}

function markContextmenuReady(L) {
    L.Map = { ...(L.Map ?? {}), ContextMenu: function ContextMenu() {} };
}

function markLeafletPluginsReady(L) {
    markDrawReady(L);
    markContextmenuReady(L);
}

function findScript(src) {
    return Array.from(document.scripts).find((script) => script.getAttribute('src') === src);
}

/** Resolves once the loader has attached its load/error listeners to the element. */
function listening(element) {
    let attached = false;
    const original = element.addEventListener;
    element.addEventListener = function (...args) {
        attached = true;
        return original.apply(this, args);
    };

    return waitUntil(() => attached);
}

module('Unit | Utility | leaflet-plugin-loader', function (hooks) {
    hooks.beforeEach(function () {
        this.originalL = window.L;
        this.originalLeaflet = window.leaflet;
        this.originalFleetopsLeafletPluginsLoaded = window.fleetopsLeafletPluginsLoaded;
        this.basePath = 'test-leaflet';
        this.inserted = [];

        // An inert element the loader will discover as already on the page; a non-JS type prevents any fetch or execution.
        this.insertScript = (src, attributes = {}) => {
            const script = document.createElement('script');
            script.type = 'text/plain';
            script.setAttribute('src', src);
            Object.entries(attributes).forEach(([name, value]) => script.setAttribute(name, value));
            document.body.appendChild(script);
            this.inserted.push(script);
            return script;
        };
        this.insertLink = (href) => {
            const link = document.createElement('link');
            link.setAttribute('href', href);
            document.head.appendChild(link);
            this.inserted.push(link);
            return link;
        };

        // Scripts the loader appends itself are neutralised the same way so the tests drive their load/error events.
        this.originalAppendChild = document.body.appendChild;
        document.body.appendChild = function (node) {
            if (node.tagName === 'SCRIPT') {
                node.type = 'text/plain';
            }
            return Element.prototype.appendChild.call(this, node);
        };

        window.L = {};
        window.leaflet = undefined;
        resetLeafletPluginLoaderForTesting();
    });

    hooks.afterEach(function () {
        document.body.appendChild = this.originalAppendChild;
        Array.from(document.querySelectorAll('[data-fleetops-leaflet-plugin="true"], [data-fleetops-leaflet-plugin-stylesheet="true"]')).forEach((element) => element.remove());
        this.inserted.forEach((element) => element.remove());
        window.L = this.originalL;
        window.leaflet = this.originalLeaflet;
        window.fleetopsLeafletPluginsLoaded = this.originalFleetopsLeafletPluginsLoaded;
        resetLeafletPluginLoaderForTesting();
    });

    test('it resolves immediately when Leaflet plugins are already present', async function (assert) {
        markLeafletPluginsReady(window.L);
        const scriptsBefore = document.scripts.length;

        const L = await ensureLeafletPluginsReady();

        assert.strictEqual(L, window.L);
        assert.strictEqual(window.leaflet, window.L);
        assert.strictEqual(document.scripts.length, scriptsBefore, 'nothing is appended');
        assert.true(window.fleetopsLeafletPluginsLoaded);
        assert.true(hasLeafletPluginsReady());
    });

    test('it loads scripts in order, reusing elements already on the page, and shares one promise', async function (assert) {
        const contextmenu = this.insertScript(CONTEXTMENU);
        const draw = this.insertScript(`${window.location.origin}${DRAW}`);
        const stylesheet = this.insertLink('/test-leaflet/leaflet.contextmenu.css');
        this.insertLink('http://[', 'an unparsable href is skipped, not fatal');
        const contextmenuListening = listening(contextmenu);

        const promiseA = ensureLeafletPluginsReady({ basePath: this.basePath, timeoutMs: null });
        const promiseB = ensureLeafletPluginsReady({ basePath: this.basePath, timeoutMs: null });

        assert.strictEqual(promiseA, promiseB, 'a load in flight is shared');
        assert.false(window.fleetopsLeafletPluginsLoaded);
        assert.strictEqual(stylesheet.dataset.fleetopsLeafletPluginStylesheet, 'true', 'an existing stylesheet is flagged rather than duplicated');
        assert.strictEqual(document.querySelectorAll('link[href="/test-leaflet/leaflet.contextmenu.css"]').length, 1);
        assert.strictEqual(document.querySelectorAll('link[href="/test-leaflet/leaflet.draw.css"]').length, 1, 'the missing stylesheet is appended');

        await contextmenuListening;
        assert.strictEqual(document.querySelectorAll(`script[src="${CONTEXTMENU}"]`).length, 1, 'the existing script is reused');
        assert.strictEqual(findScript(DRAW), undefined, 'the draw script only loads after the contextmenu script');

        const drawListening = listening(draw);
        markContextmenuReady(window.L);
        contextmenu.dispatchEvent(new Event('load'));
        await drawListening;
        assert.strictEqual(contextmenu.dataset.fleetopsLeafletPluginLoaded, 'true');
        assert.strictEqual(document.querySelectorAll('script[data-fleetops-leaflet-plugin="true"]').length, 0, 'the absolute-url draw script was matched by path and reused');

        markDrawReady(window.L);
        draw.dispatchEvent(new Event('load'));

        const L = await promiseA;
        assert.strictEqual(L, window.L);
        assert.true(window.fleetopsLeafletPluginsLoaded);
        assert.strictEqual(await ensureLeafletPluginsReady({ basePath: this.basePath }), window.L, 'later calls resolve without loading');
    });

    test('it appends missing scripts, rejects when one fails and retries on the next call', async function (assert) {
        const promise = ensureLeafletPluginsReady({ basePath: this.basePath, timeoutMs: null });

        await waitUntil(() => findScript(CONTEXTMENU));
        const script = findScript(CONTEXTMENU);
        assert.strictEqual(script.dataset.fleetopsLeafletPlugin, 'true');
        assert.false(script.async, 'plugins load in document order');

        script.dispatchEvent(new Event('error'));
        await assert.rejects(promise, /Failed to load \/test-leaflet\/leaflet.contextmenu.js/);
        assert.false(window.fleetopsLeafletPluginsLoaded);

        const retry = ensureLeafletPluginsReady({ basePath: this.basePath, timeoutMs: 20 });
        assert.notStrictEqual(retry, promise, 'a failed load is not cached');
        await assert.rejects(retry, /Timed out loading \/test-leaflet\/leaflet.contextmenu.js/, 'the reused element never loads within the timeout');
        assert.strictEqual(document.querySelectorAll(`script[src="${CONTEXTMENU}"]`).length, 1, 'the retry reuses the appended element');
    });

    test('it waits for the Leaflet global to appear and gives up after the timeout', async function (assert) {
        window.L = undefined;

        await assert.rejects(ensureLeafletPluginsReady({ basePath: this.basePath, timeoutMs: 10 }), /Leaflet global is not available/);
        assert.false(window.fleetopsLeafletPluginsLoaded);

        // Drive the poll by hand so the tick that finds nothing, and the tick that finds Leaflet, are both deterministic.
        const originalSetInterval = window.setInterval;
        let tick;
        window.setInterval = (callback, ...rest) => {
            tick = callback;
            return originalSetInterval(callback, ...rest);
        };

        const leaflet = {};
        markLeafletPluginsReady(leaflet);
        let promise;
        try {
            promise = ensureLeafletPluginsReady({ basePath: this.basePath, timeoutMs: 1000 });
        } finally {
            window.setInterval = originalSetInterval;
        }

        tick();
        assert.strictEqual(window.L, undefined, 'a tick without Leaflet keeps waiting');
        window.leaflet = leaflet;
        tick();

        assert.strictEqual(await promise, leaflet, 'the plugins are found on the global once it appears');
        assert.strictEqual(window.L, leaflet, 'both globals are normalised');
        assert.true(window.fleetopsLeafletPluginsLoaded);
    });

    test('it skips scripts whose plugin is already present, trusts flagged elements and can be forced', async function (assert) {
        markContextmenuReady(window.L);
        const draw = this.insertScript(DRAW, { 'data-fleetops-leaflet-plugin-loaded': 'true' });

        await assert.rejects(ensureLeafletPluginsReady({ basePath: this.basePath, timeoutMs: null }), /required Draw\/contextmenu APIs are missing/);
        assert.strictEqual(findScript(CONTEXTMENU), undefined, 'a plugin that is already present is not loaded');
        assert.strictEqual(draw.dataset.fleetopsLeafletPluginLoaded, 'true');

        markDrawReady(window.L);
        const forced = ensureLeafletPluginsReady({ basePath: this.basePath, timeoutMs: null, force: true });
        assert.strictEqual(await forced, window.L, 'force walks the scripts even though the plugins are ready');
        assert.strictEqual(findScript(CONTEXTMENU), undefined);
    });

    test('it waits for plain scripts to load and resolves an empty base path from the root', async function (assert) {
        const other = this.insertScript('/other.js');
        const otherListening = listening(other);

        const promise = ensureLeafletPluginsReady({ basePath: '', scripts: ['other.js'], stylesheets: [], timeoutMs: null });
        await otherListening;

        other.dispatchEvent(new Event('load'));
        await assert.rejects(promise, /required Draw\/contextmenu APIs are missing/, 'loading unrelated scripts does not make the plugins ready');
    });
});
