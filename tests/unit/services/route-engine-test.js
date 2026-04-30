import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Service | route-engine', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        class UniverseStubService extends Service {
            application = {
                hasRegistration() {
                    return false;
                },
                register() {},
                resolveRegistration() {
                    return { engines: {} };
                },
            };

            getApplicationInstance() {
                return this.application;
            }
        }

        class CurrentUserStubService extends Service {
            getOption() {
                return {
                    router: 'osrm',
                    routing_display_engine: 'google',
                    routing_optimization_engine: 'vroom',
                };
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
});
