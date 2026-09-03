'use strict';

module.exports = function (environment) {
    const ENV = {
        modulePrefix: 'dummy',
        environment,
        rootURL: '/',
        locationType: 'history',
        // Mirrors the console's `stripe` block; `customer/admin-settings` reads `publishableKey`.
        stripe: {
            publishableKey: '',
        },
        EmberENV: {
            // The engine only ever runs inside the Fleetbase console, whose config sets
            // `EXTEND_PROTOTYPES: true`; addon code relies on it (`[].pushObject`, `.uniqBy`, ...).
            // The test app mirrors the host so tests exercise the code as it actually runs.
            EXTEND_PROTOTYPES: true,
            FEATURES: {
                // Here you can enable experimental features on an ember canary build
                // e.g. EMBER_NATIVE_DECORATOR_SUPPORT: true
            },
        },

        APP: {
            // Here you can pass flags/options to your application instance
            // when it is created
        },
    };

    if (environment === 'development') {
        // ENV.APP.LOG_RESOLVER = true;
        // ENV.APP.LOG_ACTIVE_GENERATION = true;
        // ENV.APP.LOG_TRANSITIONS = true;
        // ENV.APP.LOG_TRANSITIONS_INTERNAL = true;
        // ENV.APP.LOG_VIEW_LOOKUPS = true;
    }

    if (environment === 'test') {
        // Testem prefers this...
        ENV.locationType = 'none';

        // keep test console output quieter
        ENV.APP.LOG_ACTIVE_GENERATION = false;
        ENV.APP.LOG_VIEW_LOOKUPS = false;

        ENV.APP.rootElement = '#ember-testing';
        ENV.APP.autoboot = false;

        // `@fleetbase/ember-core/services/fetch` reads `config.API.host` / `config.API.namespace` from the
        // consuming app's config via ember-get-config at module load; without them it throws
        // "Cannot read properties of undefined (reading 'host')" as an uncaught error during render.
        // Nothing in the test suite is expected to reach this host; tests stub `service:fetch`.
        ENV.API = {
            host: 'http://localhost:8000',
            namespace: 'int/v1',
        };

        // Tells tests/test-helper.js which coverage-upload hook to use: `Testem.afterTests` is the
        // reliable path in CI mode but does not fire in `ember test --server` mode.
        // See https://github.com/ember-cli-code-coverage/ember-cli-code-coverage/issues/420
        ENV.APP.isRunningWithServerArgs = process.argv.includes('--server') || process.argv.includes('-s');
    }

    if (environment === 'production') {
        // here you can enable a production-specific feature
    }

    return ENV;
};
