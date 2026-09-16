import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render, settled, triggerEvent } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import EmberObject from '@ember/object';
import Service from '@ember/service';

const baseUrls = {
    sandbox: 'https://app-public.staging.petroapp.app/webservice',
    production: 'https://app.petroapp.com.sa/webservice',
};

module('Integration | Component | fuel-integration/form', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.requests = [];
        const requests = this.requests;
        this.owner.register(
            'service:fetch',
            class extends Service {
                get() {
                    return Promise.resolve([
                        {
                            key: 'petroapp',
                            label: 'PetroApp',
                            required_fields: [
                                { name: 'api_token', label: 'Token', type: 'password', required: true },
                                {
                                    name: 'auth_type',
                                    label: 'Authentication method',
                                    type: 'select',
                                    default: 'ws_sk_header',
                                    options: [
                                        { value: 'ws_sk_header', label: 'Integration Token (WS-SK)' },
                                        { value: 'bearer_token', label: 'Bearer API Token' },
                                    ],
                                },
                            ],
                            metadata: { base_urls: baseUrls },
                        },
                    ]);
                }
                post(url, payload) {
                    requests.push({ url, payload });
                    return Promise.resolve({ success: true, message: 'PetroApp connection successful.' });
                }
            }
        );
        this.resource = EmberObject.create({
            environment: 'production',
            credentials: { api_token: 'test-token' },
            sync_settings: { window_days: 7, matching_order: ['plate_number'] },
        });
    });

    test('new connections select WS-SK and send the chosen environment to credential tests', async function (assert) {
        await render(hbs`<FuelIntegration::Form @resource={{this.resource}} />`);
        await click('button:nth-child(2)');
        assert.dom('[data-test-fuel-credential="auth_type"]').hasValue('ws_sk_header');
        assert.dom('[data-test-fuel-base-url]').includesText(baseUrls.production);

        this.element.querySelector('[data-test-fuel-environment]').value = 'sandbox';
        await triggerEvent('[data-test-fuel-environment]', 'change');
        assert.strictEqual(this.resource.environment, 'sandbox');
        assert.dom('[data-test-fuel-base-url]').includesText(baseUrls.sandbox);

        await click('button:nth-child(3)');
        await click('[data-test-fuel-test]');
        assert.strictEqual(this.requests[0].payload.environment, 'sandbox');
        assert.strictEqual(this.requests[0].payload.credentials.auth_type, 'ws_sk_header');
        assert.dom('[data-test-fuel-state]').hasText('Connection verified');

        await click('button:nth-child(2)');
        this.element.querySelector('[data-test-fuel-environment]').value = 'production';
        await triggerEvent('[data-test-fuel-environment]', 'change');
        await click('button:nth-child(3)');
        assert.dom('[data-test-fuel-state]').hasText('Ready to test');
        await click('[data-test-fuel-test]');
        assert.strictEqual(this.requests[1].payload.environment, 'production');
    });

    test('existing bearer credentials remain selected and log timestamps are stable UTC dates', async function (assert) {
        this.resource.set('provider', 'petroapp');
        await render(hbs`<FuelIntegration::Form @resource={{this.resource}} />`);
        await click('button:nth-child(2)');
        assert.dom('[data-test-fuel-credential="auth_type"]').hasValue('bearer_token');
        await click('button:nth-child(3)');
        await click('[data-test-fuel-test]');
        await click('[data-test-fuel-diagnostics-toggle]');
        const times = () => [...this.element.querySelectorAll('[data-test-fuel-log-time]')].map((element) => element.textContent);
        const initial = times();
        assert.strictEqual(initial.length, 2);
        initial.forEach((time) => assert.true(/^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\]$/.test(time)));
        this.resource.set('name', 'Updated name');
        await settled();
        assert.deepEqual(times(), initial, 'Re-rendering preserves event timestamps');
    });
});
