import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Service | leaflet-routing-control', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.routing = {};
        const routing = () => this.routing;
        const registry = { routers: {} };

        class UniverseStubService extends Service {
            application = {
                hasRegistration() {
                    return false;
                },
                register() {},
                resolveRegistration() {
                    return registry;
                },
            };

            getApplicationInstance() {
                return this.application;
            }
        }

        class CurrentUserStubService extends Service {
            getOption(key, defaultValue) {
                return Object.keys(routing()).length ? routing() : defaultValue;
            }
        }

        this.owner.register('service:universe', UniverseStubService);
        this.owner.register('service:current-user', CurrentUserStubService);
    });

    test('it exists', function (assert) {
        let service = this.owner.lookup('service:leaflet-routing-control');
        assert.ok(service);
    });

    test('it defaults to valhalla and never uses a switched-off engine', function (assert) {
        const service = this.owner.lookup('service:leaflet-routing-control');
        const osrm = { name: 'OSRM' };
        const valhalla = { name: 'Valhalla' };
        const google = { name: 'Google' };
        service.register('osrm', osrm);
        service.register('valhalla', valhalla);
        service.register('google', google);

        // Without saved routing settings the platform default is Valhalla
        assert.strictEqual(service.getRouter(), 'valhalla');
        assert.strictEqual(service.get(), valhalla);

        this.routing = { router: 'osrm', allowed_providers: ['osrm', 'valhalla'] };
        assert.strictEqual(service.get('google'), osrm, 'a switched-off engine falls back to the organization router');
        assert.strictEqual(service.get('valhalla'), valhalla);
        assert.strictEqual(service.get('mapbox'), valhalla, 'an unregistered engine falls back to Valhalla');
    });
});
