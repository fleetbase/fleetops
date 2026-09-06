import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

function cards() {
    return findAll('.fleetops-connectivity-kpi-tile').map((tile) => ({
        value: tile.querySelector('.text-xl').textContent.trim(),
        detailLabel: tile.querySelector('.mt-3 .font-semibold').textContent.trim(),
        detail: tile.querySelector('.mt-3 .truncate').textContent.trim(),
        accent: [...tile.classList].find((name) => name.startsWith('fleetops-connectivity-kpi-accent-')),
    }));
}

module('Integration | Component | telematic/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        // CustomField::Yield resolves its owner through currentUser.loadCompany, which peeks the store.
        registerTemplateOnly(this.owner, 'custom-field/yield', hbs`<div data-test-custom-fields={{@viewMode}}></div>`);
    });

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
    test('the health cards reflect an untested, unsynced integration', async function (assert) {
        this.set('telematic', { public_id: 'telematic_abc123', provider_descriptor: { label: 'Samsara', supports_webhooks: true, webhook_url: 'https://api.example.test/hook' } });

        await render(hbs`<Telematic::Details @resource={{this.telematic}} />`);

        assert.deepEqual(cards(), [
            { value: 'Not tested', detailLabel: 'Last test', detail: '-', accent: 'fleetops-connectivity-kpi-accent-blue' },
            { value: 'Not synced', detailLabel: 'Last sync', detail: '-', accent: 'fleetops-connectivity-kpi-accent-amber' },
            { value: '0', detailLabel: 'Sync job', detail: '-', accent: 'fleetops-connectivity-kpi-accent-blue' },
        ]);
        assert.dom().includesText('Offline');
        assert.dom('[data-test-custom-fields]').exists();
        assert.strictEqual(findAll('.grid.gap-2 .truncate').filter((el) => el.textContent.trim() === '-').length, 8, 'every hardware field falls back');
    });

    test('the health cards reflect a verified, synced integration with hardware identity', async function (assert) {
        this.set('telematic', {
            public_id: 'telematic_abc123',
            provider_descriptor: { label: 'Samsara', supports_webhooks: true, webhook_url: 'https://api.example.test/hook' },
            is_online: true,
            model: 'VG34',
            serial_number: 'SN-1',
            firmware_version: '2.1',
            imei: '3512',
            iccid: '8944',
            imsi: '2340',
            msisdn: '+4477',
            signal_strength: '-70 dBm',
            meta: {
                last_test_result: 'success',
                last_connection_test: '2026-05-12T03:49:26Z',
                last_sync_result: 'success',
                last_sync_completed_at: '2026-05-12T04:49:26Z',
                last_sync_total: 12,
                last_sync_job_id: 'job_9',
            },
        });

        await render(hbs`<Telematic::Details @resource={{this.telematic}} />`);

        const [test, sync, devices] = cards();
        assert.deepEqual([test.value, test.detailLabel, test.accent], ['Verified', 'Last test', 'fleetops-connectivity-kpi-accent-green']);
        assert.ok(/^1[12] May 2026, \d\d:\d\d$/.test(test.detail), 'the last test date is formatted (local time)');
        assert.deepEqual([sync.value, sync.detailLabel, sync.accent], ['Synced', 'Last sync', 'fleetops-connectivity-kpi-accent-green']);
        assert.ok(/^1[12] May 2026, \d\d:\d\d$/.test(sync.detail), 'the last sync date is formatted (local time)');
        assert.deepEqual(devices, { value: '12', detailLabel: 'Sync job', detail: 'job_9', accent: 'fleetops-connectivity-kpi-accent-green' });
        assert.dom().includesText('Online');
        assert.deepEqual(
            findAll('.grid.gap-2 .truncate').map((el) => el.textContent.trim()),
            ['VG34', 'SN-1', '2.1', '3512', '8944', '2340', '+4477', '-70 dBm']
        );
    });

    test('failed tests and syncs go rose, and a running sync goes blue with its start time', async function (assert) {
        this.set('telematic', {
            public_id: 'telematic_abc123',
            provider_descriptor: { label: 'Samsara', supports_webhooks: true, webhook_url: 'https://api.example.test/hook' },
            meta: { last_test_result: 'failed', last_sync_result: 'failed' },
        });

        await render(hbs`<Telematic::Details @resource={{this.telematic}} />`);
        assert.deepEqual(
            cards().map((card) => [card.value, card.accent]),
            [
                ['Failed', 'fleetops-connectivity-kpi-accent-rose'],
                ['Failed', 'fleetops-connectivity-kpi-accent-rose'],
                ['0', 'fleetops-connectivity-kpi-accent-blue'],
            ]
        );

        this.set('telematic', {
            public_id: 'telematic_abc123',
            status: 'synchronizing',
            provider_descriptor: { label: 'Samsara', supports_webhooks: true, webhook_url: 'https://api.example.test/hook' },
            meta: { last_sync_started_at: '2026-05-12T05:00:00Z', last_sync_result: 'failed' },
        });
        await render(hbs`<Telematic::Details @resource={{this.telematic}} />`);
        const running = cards()[1];
        assert.deepEqual([running.value, running.detailLabel, running.accent], ['Syncing provider devices', 'Started', 'fleetops-connectivity-kpi-accent-blue']);
        assert.ok(/^1[12] May 2026, \d\d:\d\d$/.test(running.detail), 'the start time is formatted (local time)');
    });

    test('sensitive provider errors are replaced by a generic message', async function (assert) {
        this.set('telematic', {
            public_id: 'telematic_abc123',
            provider_descriptor: { label: 'Samsara', supports_webhooks: true, webhook_url: 'https://api.example.test/hook' },
            meta: {
                last_error: 'SQLSTATE[HY000]: General error',
                last_sync_error: 'PDOException: connection: refused',
                unattached_devices_count: 0,
            },
        });

        await render(hbs`<Telematic::Details @resource={{this.telematic}} />`);

        assert.deepEqual(
            findAll('.bg-yellow-50 p').map((p) => p.textContent.trim()),
            ['Connection test failed. Review the provider credentials and try again.', 'Device sync failed. Review the provider connection and server logs, then try again.']
        );
        assert.dom().doesNotIncludeText('SQLSTATE');
    });

    test('a webhook url that already carries a query string appends with an ampersand', async function (assert) {
        this.set('telematic', { public_id: 'telematic_abc123', provider_descriptor: { label: 'Samsara', supports_webhooks: true, webhook_url: 'https://api.example.test/hook?v=2' } });

        await render(hbs`<Telematic::Details @resource={{this.telematic}} />`);

        assert.dom('input[readonly]').hasValue('https://api.example.test/hook?v=2&telematic=telematic_abc123');

        this.set('telematic', { public_id: 'telematic_abc123', provider_descriptor: { label: 'Samsara', supports_webhooks: true } });
        await render(hbs`<Telematic::Details @resource={{this.telematic}} />`);
        assert.dom().includesText('Webhook URL unavailable until this integration has a public ID.');
    });
});
