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

    // Same story for JointJS. `index.js` copies `@joint/core` into the host's public tree, so a
    // real app has `joint` on the global by the time the engine boots; the dummy app has no host,
    // so `joint` is undefined and `components/joint-graph.js` plus the whole of
    // `order-config-manager/activity-flow.js` cannot run at all. Only `@joint/core` is needed —
    // nothing in the addon reaches for `@joint/layout-directed-graph`.
    app.import('node_modules/@joint/core/dist/joint.min.js');

    const { maybeEmbroider } = require('@embroider/test-setup');
    return maybeEmbroider(app, {
        skipBabel: [
            {
                package: 'qunit',
            },
        ],
    });
};
