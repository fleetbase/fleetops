import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | telematic/details', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the consumer webhook url from the public id', async function (assert) {
        this.set('telematic', {
            public_id: 'telematic_abc123',
            provider_descriptor: {
                label: 'Samsara',
                supports_webhooks: true,
                webhook_url: 'https://api.example.test/telematics/webhook',
            },
        });

        await render(hbs`<Telematic::Details @resource={{this.telematic}} />`);

        assert.dom().includesText('Webhook URL');
        assert.dom('input[readonly]').hasValue('https://api.example.test/telematics/webhook?telematic=telematic_abc123');
        assert.dom().doesNotIncludeText('Webhook URL unavailable until this integration has a public ID.');
    });

    test('it explains that the webhook url is unavailable before a public id exists', async function (assert) {
        this.set('telematic', {
            id: '0f7a2c1e-0000-4000-8000-000000000000',
            uuid: '0f7a2c1e-0000-4000-8000-000000000000',
            provider_descriptor: {
                label: 'Samsara',
                supports_webhooks: true,
                webhook_url: 'https://api.example.test/telematics/webhook',
            },
        });

        await render(hbs`<Telematic::Details @resource={{this.telematic}} />`);

        assert.dom().includesText('Webhook URL unavailable until this integration has a public ID.');
        assert.dom('input[readonly]').doesNotExist();
    });

    test('it describes polling for providers without webhook support', async function (assert) {
        this.set('telematic', {
            public_id: 'telematic_abc123',
            provider_descriptor: {
                label: 'Afaqy',
                supports_webhooks: false,
            },
        });

        await render(hbs`<Telematic::Details @resource={{this.telematic}} />`);

        assert.dom().includesText('Provider polling');
        assert.dom().includesText('Fleet-Ops polls this provider for device snapshots and telemetry updates.');
        assert.dom().doesNotIncludeText('Webhook URL');
    });

    test('it stacks attention items as full width alerts', async function (assert) {
        this.set('telematic', {
            public_id: 'telematic_abc123',
            provider_descriptor: { label: 'Samsara', supports_webhooks: true, webhook_url: 'https://api.example.test/hook' },
            meta: {
                last_error: 'Connection refused by the provider.',
                last_sync_error: 'Device index returned HTTP 401.',
                unattached_devices_count: 3,
            },
        });

        await render(hbs`<Telematic::Details @resource={{this.telematic}} />`);

        const alerts = this.element.querySelectorAll('.bg-yellow-50');

        assert.strictEqual(alerts.length, 3, 'every attention item renders its own alert');
        alerts.forEach((alert) => assert.dom(alert).hasClass('w-full'));

        const alertList = alerts[0].parentElement;

        assert.dom(alertList).hasClass('flex');
        assert.dom(alertList).hasClass('flex-col');
        assert.dom(alertList).hasClass('gap-3');
        assert.strictEqual(this.element.querySelector('.lg\\:grid-cols-2'), null, 'attention items are never laid out in a two column grid');
    });

    test('it confirms when nothing needs attention', async function (assert) {
        this.set('telematic', {
            public_id: 'telematic_abc123',
            provider_descriptor: { label: 'Samsara', supports_webhooks: true, webhook_url: 'https://api.example.test/hook' },
        });

        await render(hbs`<Telematic::Details @resource={{this.telematic}} />`);

        assert.dom().includesText('No immediate attention needed');
        assert.strictEqual(this.element.querySelectorAll('.bg-yellow-50').length, 0);
    });
});
