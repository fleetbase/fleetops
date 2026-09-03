import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render, waitUntil } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import config from 'ember-get-config';
import { setupWindowMock } from 'ember-window-mock/test-support';
import window from 'ember-window-mock';

module('Integration | Component | customer/admin-settings', function (hooks) {
    setupRenderingTest(hooks);
    setupWindowMock(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        const test = this;
        this.enabled = ['oc_1'];
        this.paymentsConfig = { paymentsEnabled: false, paymentsOnboardCompleted: true };
        this.getFails = false;
        this.postFails = false;
        this.findAllFails = false;
        this.owner.register(
            'service:fetch',
            class extends Service {
                async get(url) {
                    calls.push(['get', url]);
                    if (test.getFails) {
                        throw new Error('api down');
                    }
                    return url.endsWith('customer-payments-config') ? test.paymentsConfig : test.enabled;
                }

                async post(url, body) {
                    calls.push(['post', url, JSON.parse(JSON.stringify(body))]);
                    if (test.postFails) {
                        throw new Error('save failed');
                    }
                    return body;
                }
            }
        );
        this.owner.register(
            'service:notifications',
            class extends Service {
                success(message) {
                    calls.push(['success', message]);
                }

                warning(message) {
                    calls.push(['warning', message]);
                }

                serverError(error) {
                    calls.push(['serverError', error.message]);
                }
            }
        );
        const store = this.owner.lookup('service:store');
        store.findAll = (type) => {
            calls.push(['findAll', type]);
            if (test.findAllFails) {
                throw new Error('no such model');
            }
            return [
                { id: 'oc_1', name: 'Delivery' },
                { id: 'oc_2', name: 'Pickup' },
            ];
        };
        this.publishableKey = config.stripe.publishableKey;
    });

    hooks.afterEach(function () {
        config.stripe.publishableKey = this.publishableKey;
    });

    test('it loads the order types and toggles which ones customers may use', async function (assert) {
        await render(hbs`<Customer::AdminSettings />`);

        assert.deepEqual(this.calls.slice(0, 3), [
            ['findAll', 'order-config'],
            ['get', 'fleet-ops/settings/customer-enabled-order-configs'],
            ['get', 'fleet-ops/settings/customer-payments-config'],
        ]);
        assert.dom('.fleetbase-checkbox').exists({ count: 2 });
        assert.deepEqual(
            findAll('.fleetbase-checkbox').map((input) => input.checked),
            [true, false]
        );
        assert.dom().includesText('Delivery');
        assert.dom().includesText('Pickup');
        assert.dom().includesText('Stripe is NOT configured');
        assert.dom('[role="checkbox"]').doesNotHaveAttribute('data-disabled');
        assert.dom().doesNotIncludeText('Payment onboard must be completed');

        await click(findAll('.fleetbase-checkbox')[1]);
        assert.deepEqual(this.calls.at(-2), ['post', 'fleet-ops/settings/customer-enabled-order-configs', { enabledOrderConfigs: ['oc_1', 'oc_2'] }]);
        assert.deepEqual(this.calls.at(-1), ['success', 'Settings saved.']);

        this.postFails = true;
        await click(findAll('.fleetbase-checkbox')[0]);
        assert.deepEqual(this.calls.at(-2), ['post', 'fleet-ops/settings/customer-enabled-order-configs', { enabledOrderConfigs: ['oc_2'] }]);
        assert.deepEqual(this.calls.at(-1), ['serverError', 'save failed']);
    });

    test('payments can only be enabled once stripe is configured', async function (assert) {
        await render(hbs`<Customer::AdminSettings />`);

        await click('[role="checkbox"]');
        assert.deepEqual(this.calls.at(-1), ['warning', 'You must configure Stripe first to accept payments.']);
        assert.dom('[role="checkbox"]').hasAttribute('aria-checked', 'false');
        assert.notOk(this.calls.some((call) => call[1] === 'fleet-ops/settings/customer-payments-config' && call[0] === 'post'));

        window.stripeInstance = {};
        await render(hbs`<Customer::AdminSettings />`);
        assert.dom().includesText('Stripe is configured.');

        await click('[role="checkbox"]');
        assert.deepEqual(this.calls.at(-2), ['post', 'fleet-ops/settings/customer-payments-config', { paymentsConfig: { paymentsEnabled: true, paymentGateway: 'stripe' } }]);
        assert.deepEqual(this.calls.at(-1), ['success', 'Settings saved.']);
        assert.dom('[role="checkbox"]').hasAttribute('aria-checked', 'true');

        this.postFails = true;
        await click('[role="checkbox"]');
        assert.deepEqual(this.calls.at(-2), ['post', 'fleet-ops/settings/customer-payments-config', { paymentsConfig: { paymentsEnabled: false, paymentGateway: 'stripe' } }]);
        assert.deepEqual(this.calls.at(-1), ['serverError', 'save failed']);
    });

    test('a publishable key also counts as stripe being configured', async function (assert) {
        config.stripe.publishableKey = 'pk_test_123';
        this.paymentsConfig = { paymentsEnabled: true, paymentsOnboardCompleted: false };

        await render(hbs`<Customer::AdminSettings />`);

        assert.dom().includesText('Stripe is configured.');
        assert.dom('[role="checkbox"]').hasAttribute('aria-checked', 'true');
        assert.dom('[role="checkbox"]').hasAttribute('data-disabled');
        assert.dom().includesText('Payment onboard must be completed');
        assert.dom('button').includesText('Completed Payments Onboard');
    });

    test('a missing payments config and failing loads are reported without crashing', async function (assert) {
        this.paymentsConfig = null;
        await render(hbs`<Customer::AdminSettings />`);
        assert.dom('[role="checkbox"]').hasAttribute('aria-checked', 'false');
        assert.dom('[role="checkbox"]').hasAttribute('data-disabled');

        this.calls.length = 0;
        this.getFails = true;
        await render(hbs`<Customer::AdminSettings />`);
        await waitUntil(() => this.calls.filter((call) => call[0] === 'serverError').length === 2);
        assert.deepEqual(
            this.calls.filter((call) => call[0] === 'serverError'),
            [
                ['serverError', 'api down'],
                ['serverError', 'api down'],
            ]
        );
        assert.deepEqual(
            findAll('.fleetbase-checkbox').map((input) => input.checked),
            [false, false],
            'the order types still list, none enabled'
        );

        this.calls.length = 0;
        this.findAllFails = true;
        await render(hbs`<Customer::AdminSettings />`);
        await waitUntil(() => this.calls.filter((call) => call[0] === 'serverError').length === 3);
        assert.deepEqual(this.calls.filter((call) => call[0] === 'serverError')[0], ['serverError', 'no such model']);
        assert.dom('.fleetbase-checkbox').doesNotExist();
    });
});
