import Application from '@ember/application';
import config from 'dummy/config/environment';
import { initialize } from '@fleetbase/fleetops-engine/instance-initializers/register-osrm';
import { module, test } from 'qunit';
import Resolver from 'ember-resolver';
import { run } from '@ember/runloop';

module('Unit | Instance Initializer | register-osrm', function (hooks) {
    hooks.beforeEach(function () {
        this.TestApplication = class TestApplication extends Application {
            modulePrefix = config.modulePrefix;
            podModulePrefix = config.podModulePrefix;
            Resolver = Resolver;
        };

        // The console hands the universe its application instance while booting engines; the
        // registry-backed routing services this initializer fills need that before they instantiate.
        this.TestApplication.instanceInitializer({
            name: 'universe application instance',
            before: 'initializer under test',
            initialize(owner) {
                owner.lookup('service:universe').setApplicationInstance(owner);
            },
        });

        this.TestApplication.instanceInitializer({
            name: 'initializer under test',
            initialize,
        });

        this.application = this.TestApplication.create({
            autoboot: false,
        });

        this.instance = this.application.buildInstance();
    });
    hooks.afterEach(function () {
        run(this.instance, 'destroy');
        run(this.application, 'destroy');
    });

    test('it registers OSRM as the optimization, display and routing-control engine', async function (assert) {
        await this.instance.boot();

        const osrm = this.instance.lookup('service:osrm');
        const routeOptimization = this.instance.lookup('service:route-optimization');
        const routeEngine = this.instance.lookup('service:route-engine');
        const leafletRoutingControl = this.instance.lookup('service:leaflet-routing-control');

        assert.strictEqual(routeOptimization.registry.engines.osrm, osrm, 'OSRM optimizes routes');
        assert.strictEqual(routeEngine.get('osrm'), osrm, 'OSRM is a display route engine');
        assert.true(routeEngine.registry.engines.osrm.capabilities.display);

        const control = leafletRoutingControl.get('osrm');
        assert.strictEqual(control.name, 'OSRM');
        assert.strictEqual(control.router.options.profile, 'driving');
        assert.true(control.router.options.serviceUrl.endsWith('/route/v1'), 'the router points at the routing host');
    });
});
