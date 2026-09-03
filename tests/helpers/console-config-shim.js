/* global define, requirejs */

/**
 * Registers stand-ins for three modules that `@fleetbase/ember-core` imports at load time and that
 * only the host console provides: `@fleetbase/console/config/environment`,
 * `@fleetbase/console/extensions`, and the deprecated `ember-fetch` AMD module `fetch`.
 *
 * `@fleetbase/ember-core/utils/console-url`, `api-url`, `frontend-url` and `get-routing-host` import
 * that module at the top level. In production the engine runs inside the console, which provides
 * it; the dummy test app has no such module, so any addon module that (transitively) imports one
 * of those utils fails to load with "Could not find module `@fleetbase/console/config/environment`".
 *
 * This file must be imported before `dummy/app` in `tests/test-helper.js` so the shim exists before
 * any initializer or addon module is evaluated.
 */
const MODULE_NAME = '@fleetbase/console/config/environment';
const EXTENSIONS_MODULE_NAME = '@fleetbase/console/extensions';
const FETCH_MODULE_NAME = 'fetch';

if (!requirejs.entries[MODULE_NAME]) {
    define(MODULE_NAME, [], function () {
        return {
            default: {
                environment: 'test',
                modulePrefix: '@fleetbase/console',
                rootURL: '/',
                API: {
                    host: 'http://localhost:8000',
                    namespace: 'int/v1',
                },
                socket: {
                    path: '/socket',
                    port: 38000,
                },
                osrm: {
                    host: 'https://router.project-osrm.org',
                    servers: {
                        us: 'https://router.project-osrm.org',
                        ca: 'https://router.project-osrm.org',
                    },
                },
            },
        };
    });
}

// `@fleetbase/ember-core/services/universe/extension-manager` imports `getExtensionLoader` from the
// console's build-time generated extension map. No extensions are loadable in the dummy app, so the
// loader lookup returns `undefined`, which the extension manager treats as "no loader registered".
if (!requirejs.entries[EXTENSIONS_MODULE_NAME]) {
    define(EXTENSIONS_MODULE_NAME, [], function () {
        return {
            getExtensionLoader() {
                return undefined;
            },
        };
    });
}

// `@fleetbase/ember-core/services/fetch` does `import fetch from 'fetch'` — the AMD module the
// deprecated `ember-fetch` addon defines. Neither ember-core nor this package depends on it; the
// console does. Native fetch has the same surface, so the shim just re-exports the globals.
if (!requirejs.entries[FETCH_MODULE_NAME]) {
    define(FETCH_MODULE_NAME, [], function () {
        return {
            default: window.fetch.bind(window),
            fetch: window.fetch.bind(window),
            Headers: window.Headers,
            Request: window.Request,
            Response: window.Response,
            AbortController: window.AbortController,
        };
    });
}
