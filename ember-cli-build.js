'use strict';

const EmberAddon = require('ember-cli/lib/broccoli/ember-addon');

module.exports = function (defaults) {
    const app = new EmberAddon(defaults, {
        // Add options here
    });

    /*
    This build file specifies the options for the dummy test app of this
    addon, located in `/tests/dummy`
    This build file does *not* influence how the addon or the app using it
    behave. You most likely want to be modifying `./index.js` or app's build file
  */

    // The addon reads Leaflet off the global in seven modules, several of them capturing it at
    // module scope (`const L = window.leaflet || window.L`). In a real app the host console loads
    // Leaflet before the engine; the dummy app has no host, so the global is undefined and every
    // one of those modules is untestable. Importing the real library here gives the test app the
    // same global the host provides. This file only builds the dummy app — the published addon is
    // unaffected.
    app.import('node_modules/leaflet/dist/leaflet.js');

    const { maybeEmbroider } = require('@embroider/test-setup');
    return maybeEmbroider(app, {
        skipBabel: [
            {
                package: 'qunit',
            },
        ],
    });
};
