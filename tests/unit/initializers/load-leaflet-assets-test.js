import Application from '@ember/application';

import config from 'dummy/config/environment';
import { initialize } from '@fleetbase/fleetops-engine/initializers/load-leaflet-assets';
import { module, test } from 'qunit';
import Resolver from 'ember-resolver';
import { run } from '@ember/runloop';
import { waitUntil } from '@ember/test-helpers';
import { resetLeafletPluginLoaderForTesting } from '@fleetbase/fleetops-engine/utils/leaflet-plugin-loader';

module('Unit | Initializer | load-leaflet-assets', function (hooks) {
    hooks.beforeEach(function () {
        this.originalL = window.L;
        this.originalLeaflet = window.leaflet;
        this.originalFleetopsLeafletPluginsLoaded = window.fleetopsLeafletPluginsLoaded;
        this.originalSetInterval = window.setInterval;
        this.originalClearInterval = window.clearInterval;

        // The initializer polls for the Leaflet global every 100ms. Drive that poll by hand so the
        // test is deterministic and never leaves a timer running into the next test.
        this.ticks = [];
        this.cleared = [];
        window.setInterval = (callback) => {
            this.ticks.push(callback);
            return this.ticks.length;
        };
        window.clearInterval = (id) => this.cleared.push(id);

        this.TestApplication = class TestApplication extends Application {
            modulePrefix = config.modulePrefix;
            podModulePrefix = config.podModulePrefix;
            Resolver = Resolver;
        };

        this.TestApplication.initializer({
            name: 'initializer under test',
            initialize,
        });

        this.application = this.TestApplication.create({
            autoboot: false,
        });

        // The initializer hands off to the plugin loader, which would append real plugin scripts and run
        // them against the stub Leaflet below; keep any script it appends inert.
        this.originalAppendChild = document.body.appendChild;
        document.body.appendChild = function (node) {
            if (node.tagName === 'SCRIPT') {
                node.type = 'text/plain';
            }
            return Element.prototype.appendChild.call(this, node);
        };
        resetLeafletPluginLoaderForTesting();
    });

    hooks.afterEach(function () {
        window.setInterval = this.originalSetInterval;
        window.clearInterval = this.originalClearInterval;
        document.body.appendChild = this.originalAppendChild;
        Array.from(document.querySelectorAll('[data-fleetops-leaflet-plugin="true"], [data-fleetops-leaflet-plugin-stylesheet="true"]')).forEach((element) => element.remove());
        window.L = this.originalL;
        window.leaflet = this.originalLeaflet;
        window.fleetopsLeafletPluginsLoaded = this.originalFleetopsLeafletPluginsLoaded;
        resetLeafletPluginLoaderForTesting();
        run(this.application, 'destroy');
    });

    test('it guards the Leaflet Draw edit namespace once the Leaflet global appears', async function (assert) {
        window.L = undefined;
        window.leaflet = undefined;

        await this.application.boot();
        assert.strictEqual(this.ticks.length, 1, 'one poll is started');

        this.ticks[0]();
        assert.deepEqual(this.cleared, [], 'the poll keeps waiting while Leaflet is absent');

        window.L = {};
        this.ticks[0]();

        assert.deepEqual(this.cleared, [1], 'the poll stops once Leaflet is found');
        assert.deepEqual(window.L.Edit, {}, 'the Draw edit namespace is guarded before any plugin loads');
        assert.strictEqual(window.leaflet, window.L, 'both globals are normalised');

        // The stub Leaflet can never satisfy the plugin loader, so its promise rejects; the
        // initializer must log that through `debug` rather than let it escape. Depending on what
        // earlier tests left on the page the loader either reuses flagged plugin scripts (and
        // rejects on the missing Draw/contextmenu APIs) or appends inert ones that need an error.
        const messages = [];
        const originalDebug = console.debug;
        console.debug = (...args) => {
            messages.push(args.join(' '));
            return originalDebug.apply(console, args);
        };
        const logged = () => messages.some((message) => message.includes('[Fleet-Ops Leaflet]'));
        const pendingScript = () =>
            Array.from(document.scripts).find(
                (script) => script.getAttribute('src') === '/engines-dist/leaflet/leaflet.contextmenu.js' && script.dataset.fleetopsLeafletPluginLoaded !== 'true'
            );

        try {
            await waitUntil(() => logged() || pendingScript());
            if (!logged()) {
                pendingScript().dispatchEvent(new Event('error'));
                await waitUntil(logged);
            }
        } finally {
            console.debug = originalDebug;
        }

        assert.true(logged(), 'a failed plugin load is logged, not thrown');
    });
});
