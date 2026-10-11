import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Service | order-allocation', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        const context = this;
        this.settings = { allocation_engine: 'vroom' };

        this.owner.register(
            'service:fetch',
            class extends Service {
                get() {
                    return Promise.resolve(context.settings);
                }
            }
        );
        this.owner.register('service:notifications', class extends Service {});
    });

    test('it reads the active engine from the saved orchestrator settings', async function (assert) {
        const service = this.owner.lookup('service:order-allocation');

        await service.loadSettings.perform();

        assert.strictEqual(service.activeEngineId, 'vroom');
    });

    test('it defaults to the built-in greedy engine when none is saved', async function (assert) {
        this.settings = {};
        const service = this.owner.lookup('service:order-allocation');

        await service.loadSettings.perform();

        assert.strictEqual(service.activeEngineId, 'greedy');
    });
});
