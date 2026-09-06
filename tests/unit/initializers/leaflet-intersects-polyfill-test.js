import Application from '@ember/application';
import config from 'dummy/config/environment';
import { initialize } from '@fleetbase/fleetops-engine/initializers/leaflet-intersects-polyfill';
import { module, test } from 'qunit';
import Resolver from 'ember-resolver';
import { run } from '@ember/runloop';

module('Unit | Initializer | leaflet-intersects-polyfill', function (hooks) {
    hooks.beforeEach(function () {
        this.originalL = window.L;
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
    });

    hooks.afterEach(function () {
        window.setInterval = this.originalSetInterval;
        window.clearInterval = this.originalClearInterval;
        window.L = this.originalL;
        run(this.application, 'destroy');
    });

    test('it polyfills Bounds#intersects once the Leaflet global appears', async function (assert) {
        window.L = undefined;

        await this.application.boot();
        assert.strictEqual(this.ticks.length, 1, 'one poll is started');

        this.ticks[0]();
        assert.deepEqual(this.cleared, [], 'the poll keeps waiting while Leaflet is absent');

        const Bounds = function () {};
        window.L = { Bounds };
        this.ticks[0]();
        assert.deepEqual(this.cleared, [1], 'the poll stops once Leaflet is found');

        const bounds = (min, max) => ({ min, max });
        const intersects = Bounds.prototype.intersects;
        assert.true(intersects.call(bounds({ x: 0, y: 0 }, { x: 10, y: 10 }), bounds({ x: 5, y: 5 }, { x: 15, y: 15 })), 'overlapping bounds intersect');
        assert.true(intersects.call(bounds({ x: 0, y: 0 }, { x: 10, y: 10 }), bounds({ x: 10, y: 10 }, { x: 15, y: 15 })), 'touching bounds intersect');
        assert.false(intersects.call(bounds({ x: 0, y: 0 }, { x: 10, y: 10 }), bounds({ x: 11, y: 0 }, { x: 15, y: 10 })), 'bounds apart on x do not intersect');
        assert.false(intersects.call(bounds({ x: 0, y: 0 }, { x: 10, y: 10 }), bounds({ x: 0, y: 11 }, { x: 10, y: 15 })), 'bounds apart on y do not intersect');
    });
});
