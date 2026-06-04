import Application from '@ember/application';

import config from 'dummy/config/environment';
import { initialize } from 'dummy/initializers/load-leaflet-assets';
import { module, test } from 'qunit';
import Resolver from 'ember-resolver';
import { run } from '@ember/runloop';

module('Unit | Initializer | load-leaflet-assets', function (hooks) {
    hooks.beforeEach(function () {
        this.originalL = window.L;
        this.originalLeaflet = window.leaflet;
        this.originalFleetopsLeafletPluginsLoaded = window.fleetopsLeafletPluginsLoaded;

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
        window.L = this.originalL;
        window.leaflet = this.originalLeaflet;
        window.fleetopsLeafletPluginsLoaded = this.originalFleetopsLeafletPluginsLoaded;
        run(this.application, 'destroy');
    });

    test('it guards the Leaflet Draw edit namespace before plugin loading can create markers', async function (assert) {
        window.L = {};
        window.leaflet = undefined;

        await this.application.boot();

        assert.deepEqual(window.L.Edit, {});
    });
});
