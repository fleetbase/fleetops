import { module, test } from 'qunit';
import TelematicDetailsComponent from 'dummy/components/telematic/details';

function makeDetails(resource) {
    const component = Object.create(TelematicDetailsComponent.prototype);
    component.args = { resource };

    return component;
}

module('Unit | Component | telematic/details', function () {
    test('webhook url is built from the public id', function (assert) {
        const component = makeDetails({
            public_id: 'telematic_abc123',
            uuid: '0f7a2c1e-0000-4000-8000-000000000000',
            provider_descriptor: { webhook_url: 'https://api.example.test/telematics/webhook' },
        });

        assert.strictEqual(component.webhookUrl, 'https://api.example.test/telematics/webhook?telematic=telematic_abc123');
        assert.true(component.hasWebhookUrl);
    });

    test('webhook url appends to an existing query string', function (assert) {
        const component = makeDetails({
            public_id: 'telematic_abc123',
            provider_descriptor: { webhook_url: 'https://api.example.test/telematics/webhook?provider=samsara' },
        });

        assert.strictEqual(component.webhookUrl, 'https://api.example.test/telematics/webhook?provider=samsara&telematic=telematic_abc123');
    });

    test('webhook url is unavailable when the record only has ember identifiers', function (assert) {
        const component = makeDetails({
            id: '0f7a2c1e-0000-4000-8000-000000000000',
            uuid: '0f7a2c1e-0000-4000-8000-000000000000',
            provider_descriptor: { webhook_url: 'https://api.example.test/telematics/webhook' },
        });

        assert.strictEqual(component.webhookUrl, null, 'the ember uuid is never used as the public webhook identifier');
        assert.false(component.hasWebhookUrl);
    });

    test('webhook url is unavailable when the provider has no webhook endpoint', function (assert) {
        const component = makeDetails({ public_id: 'telematic_abc123', provider_descriptor: {} });

        assert.strictEqual(component.webhookUrl, null);
        assert.false(component.hasWebhookUrl);
    });

    test('the devices synced card surfaces the last sync job id', function (assert) {
        const component = makeDetails({
            meta: { last_sync_total: 12, last_sync_job_id: 'job_9f21' },
        });
        const devicesSynced = component.healthCards.find((card) => card.label === 'Devices synced');

        assert.strictEqual(devicesSynced.value, 12);
        assert.strictEqual(devicesSynced.detail, 'job_9f21');
        assert.false(devicesSynced.detailIsDate);
    });

    test('a last sync error is surfaced as an attention item', function (assert) {
        const component = makeDetails({
            meta: { last_sync_error: 'Provider returned HTTP 401 for the device index.' },
        });

        assert.deepEqual(
            component.attentionItems.map((item) => item.title),
            ['Sync issue']
        );
        assert.strictEqual(component.attentionItems[0].description, 'Provider returned HTTP 401 for the device index.');
    });

    test('sensitive sync errors are replaced with a safe operator message', function (assert) {
        const component = makeDetails({
            meta: { last_sync_error: 'SQLSTATE[42S02]: Base table or view not found' },
        });

        assert.strictEqual(component.attentionItems[0].description, 'Device sync failed. Review the provider connection and server logs, then try again.');
    });
});
