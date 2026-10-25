import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Service | route-engine', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        const registry = { engines: {} };
        this.registry = registry;
        this.routing = {
            router: 'osrm',
            routing_display_engine: 'google',
            routing_optimization_engine: 'vroom',
        };
        const routing = () => this.routing;

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
            getOption() {
                return routing();
            }
        }

        this.owner.register('service:universe', UniverseStubService);
        this.owner.register('service:current-user', CurrentUserStubService);
    });

    test('it resolves display and optimization settings from routing options', function (assert) {
        const service = this.owner.lookup('service:route-engine');

        assert.strictEqual(service.getDisplayEngine(), 'google');
        assert.strictEqual(service.getOptimizationEngine(), 'vroom');
    });

    test('it hides and never uses engines the administrator switched off', function (assert) {
        const service = this.owner.lookup('service:route-engine');
        const osrm = { name: 'OSRM', computeRoute: () => 'osrm-route' };
        const google = { name: 'Google', computeRoute: () => 'google-route' };
        const mapbox = { name: 'Mapbox', computeRoute: () => 'mapbox-route' };
        service.register('osrm', osrm);
        service.register('google', google);
        service.register('mapbox', mapbox);

        this.routing = { routing_display_engine: 'osrm', allowed_providers: ['osrm', 'valhalla'] };

        assert.deepEqual(
            service.availableServices.map(({ key }) => key),
            ['osrm', 'mapbox'],
            'google is not offered; extension engines are'
        );
        assert.deepEqual(
            service.allServices.map(({ key }) => key),
            ['osrm', 'google', 'mapbox'],
            'admin screens still see every engine'
        );
        assert.strictEqual(service.get('google'), osrm, 'a switched-off engine falls back to the display engine');
        assert.strictEqual(service.get('mapbox'), mapbox);

        // Without an allowed list nothing is filtered
        this.routing = { routing_display_engine: 'google' };
        assert.strictEqual(service.get('google'), google);
        assert.strictEqual(service.availableServices.length, 3);
    });
});
