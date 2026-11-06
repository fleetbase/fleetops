import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

const STRATEGIES = [
    { name: 'broadcast', label: 'Everyone nearby at once', description: 'Every nearby driver at once.' },
    { name: 'sequential', label: 'One driver at a time', description: 'The nearest driver first.' },
];

module('Unit | Controller | settings/dispatch', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        this.owner.register(
            'service:fetch',
            class extends Service {
                get(url) {
                    calls.push(['get', url]);
                    return Promise.resolve({ adhoc_distance: 5000, adhoc_strategy: 'sequential', adhoc_offer_timeout: 25, strategies: STRATEGIES });
                }

                post(url, body) {
                    calls.push(['post', url, body]);
                    return Promise.resolve({ status: 'ok' });
                }
            }
        );
        const notified = (this.notified = []);
        this.owner.register(
            'service:notifications',
            class extends Service {
                success(message) {
                    notified.push(['success', message]);
                }

                serverError(error) {
                    notified.push(['error', error]);
                }
            }
        );
    });

    test('it loads the company defaults and the strategies to choose from', async function (assert) {
        const controller = this.owner.lookup('controller:settings/dispatch');
        await controller.getSettings.last;

        assert.strictEqual(controller.adhocDistance, 5000);
        assert.strictEqual(controller.adhocStrategy, 'sequential');
        assert.strictEqual(controller.adhocOfferTimeout, 25);
        assert.strictEqual(controller.selectedStrategy.label, 'One driver at a time');
        assert.true(controller.usesOfferTimeout);

        controller.adhocStrategy = 'broadcast';
        assert.false(controller.usesOfferTimeout, 'everyone at once has no offer time');
    });

    test('it saves the settings as numbers', async function (assert) {
        const controller = this.owner.lookup('controller:settings/dispatch');
        await controller.getSettings.last;

        controller.adhocDistance = '8000';
        controller.adhocOfferTimeout = '30';
        controller.adhocStrategy = 'broadcast';
        await controller.saveSettings.perform();

        assert.deepEqual(this.calls[this.calls.length - 1], [
            'post',
            'fleet-ops/settings/adhoc-dispatch-settings',
            { adhoc_distance: 8000, adhoc_strategy: 'broadcast', adhoc_offer_timeout: 30 },
        ]);
        assert.strictEqual(this.notified[0][0], 'success');
    });
});
