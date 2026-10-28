import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Service | route-optimization', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.routing = {};
        const routing = () => this.routing;
        const registry = { engines: {} };

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

        this.owner.register('service:universe', UniverseStubService);

        class CurrentUserStubService extends Service {
            getOption() {
                return routing();
            }
        }

        this.owner.register('service:current-user', CurrentUserStubService);
    });

    test('it exists', function (assert) {
        let service = this.owner.lookup('service:route-optimization');
        assert.ok(service);
    });

    test('it hides and refuses engines the administrator switched off', async function (assert) {
        const service = this.owner.lookup('service:route-optimization');
        service.register('osrm', { name: 'OSRM', optimize: () => Promise.resolve('osrm'), handler: () => 'osrm-handled' });
        service.register('vroom', { name: 'VROOM', optimize: () => Promise.resolve('vroom'), handler: () => 'vroom-handled' });

        this.routing = { allowed_providers: ['osrm'] };

        assert.deepEqual(
            service.availableServices.map(({ key }) => key),
            ['osrm']
        );
        assert.deepEqual(service.availableEngines, ['osrm']);
        assert.deepEqual(
            service.allServices.map(({ key }) => key),
            ['osrm', 'vroom']
        );
        assert.strictEqual(await service.optimize('osrm'), 'osrm');
        assert.strictEqual(service.handler('osrm'), 'osrm-handled');

        await assert.rejects(service.optimize('vroom'), /switched off by the administrator/);
        assert.throws(() => service.handler('vroom'), /switched off by the administrator/);

        this.routing = {};
        assert.strictEqual(await service.optimize('vroom'), 'vroom');
    });
});
