import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Controller | settings/orchestrator', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        const context = this;
        this.responses = {
            'fleet-ops/settings/orchestrator-settings': { max_travel_time_seconds: 1800 },
            'fleet-ops/orchestrator/engines': {
                engines: [
                    { id: 'greedy', name: 'Greedy (built-in)' },
                    { id: 'vroom', name: 'VROOM' },
                ],
            },
        };

        this.owner.register(
            'service:fetch',
            class extends Service {
                get(url) {
                    const response = context.responses[url];
                    return response instanceof Error ? Promise.reject(response) : Promise.resolve(response);
                }
            }
        );
        this.owner.register(
            'service:notifications',
            class extends Service {
                serverError(error) {
                    throw error;
                }
            }
        );
        this.owner.register(
            'service:orchestration-engine',
            class extends Service {
                availableEngines = [{ id: 'vroom', name: 'VROOM' }];
            }
        );
    });

    test('it offers the engines the server can run and defaults to greedy', async function (assert) {
        const controller = this.owner.lookup('controller:settings/orchestrator');

        await controller.loadSettings.perform();

        assert.strictEqual(controller.allocationEngine, 'greedy');
        assert.strictEqual(controller.maxTravelTimeSeconds, 1800);
        assert.deepEqual(
            controller.engineOptions.map((engine) => engine.id),
            ['greedy', 'vroom']
        );
    });

    test('it shows the saved engine and falls back to the frontend registry when engines cannot be listed', async function (assert) {
        this.responses['fleet-ops/settings/orchestrator-settings'] = { allocation_engine: 'vroom' };
        this.responses['fleet-ops/orchestrator/engines'] = new Error('unavailable');
        const controller = this.owner.lookup('controller:settings/orchestrator');

        await controller.loadSettings.perform();

        assert.strictEqual(controller.allocationEngine, 'vroom');
        assert.deepEqual(controller.engineOptions, [{ id: 'vroom', name: 'VROOM' }]);
    });
});
