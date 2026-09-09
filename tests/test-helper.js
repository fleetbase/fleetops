import Application from 'dummy/app';
import config from 'dummy/config/environment';
import * as QUnit from 'qunit';
import { setApplication } from '@ember/test-helpers';
import { setup } from 'qunit-dom';
import { start } from 'ember-qunit';

// The universe extension manager imports a module the console host generates at build
// time. The engine's dummy app has no host, so provide an inert stand-in before boot.
if (typeof window.define === 'function' && !window.requirejs?.entries?.['@fleetbase/console/extensions']) {
    window.define('@fleetbase/console/extensions', ['exports'], function (exports) {
        exports.getExtensionLoader = function () {
            return null;
        };
        exports.default = {};
    });
}

setApplication(Application.create(config.APP));

setup(QUnit.assert);

start();
