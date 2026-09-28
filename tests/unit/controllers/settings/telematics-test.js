import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { settled } from '@ember/test-helpers';
import Service from '@ember/service';

const SETTINGS = {
    event_retention_days: 12,
    event_compact_after_days: 12,
    position_retention_days: 0,
    processed_retention_hours: 6,
    quarantine_retention_days: 3,
    sync_run_retention_days: 2,
    log_telemetry_activity: true,
    defaults: { event_retention_days: 30 },
    limits: { event_retention_days: [1, 3650] },
};

const USAGE = {
    tables: {
        device_events: { rows: 1200, oldest: '2026-08-01 00:00:00', raw_payload_rows: 300, compactable_rows: 100, avg_row_bytes: 32000, estimated_bytes: 38400000 },
        positions: { rows: 40, oldest: null },
        telematic_deliveries: { rows: 0, oldest: null },
    },
};

class FetchStubService extends Service {
    posts = [];

    get(url) {
        if (url === 'fleet-ops/settings/telematics-settings') {
            return Promise.resolve(SETTINGS);
        }
        if (url === 'fleet-ops/settings/telematics-storage-usage') {
            return Promise.resolve(USAGE);
        }

        return Promise.resolve({});
    }

    post(url, body) {
        this.posts.push([url, body]);
        return Promise.resolve({ ...body, status: 'ok' });
    }
}

class NotificationsStubService extends Service {
    successes = [];
    errors = [];

    success(message) {
        this.successes.push(message);
    }

    serverError(error) {
        this.errors.push(error);
    }
}

class CurrentUserStubService extends Service {
    isAdmin = false;
}

module('Unit | Controller | settings/telematics', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:notifications', NotificationsStubService);
        this.owner.register('service:current-user', CurrentUserStubService);
    });

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:settings/telematics');
        assert.ok(controller);
    });

    test('it loads settings and usage, flags ineffective compaction and saves integers', async function (assert) {
        const controller = this.owner.lookup('controller:settings/telematics');
        await settled();

        assert.strictEqual(controller.eventRetentionDays, 12);
        assert.strictEqual(controller.positionRetentionDays, 0);
        assert.true(controller.logTelemetryActivity);
        assert.deepEqual(controller.defaults, { event_retention_days: 30 });
        assert.true(controller.compactionIneffective, 'compacting at the deletion age is pointless');
        assert.false(controller.isAdmin);

        assert.deepEqual(
            controller.usageRows.map((row) => row.table),
            ['device_events', 'positions', 'telematic_deliveries']
        );
        assert.strictEqual(controller.usageRows[0].estimated_bytes, 38400000);

        controller.eventCompactAfterDays = '5';
        controller.eventRetentionDays = '';
        controller.processedRetentionHours = 'abc';
        assert.false(controller.compactionIneffective);
        await controller.saveSettings.perform();

        const fetch = this.owner.lookup('service:fetch');
        assert.deepEqual(fetch.posts, [
            [
                'fleet-ops/settings/telematics-settings',
                {
                    event_retention_days: 0,
                    event_compact_after_days: 5,
                    position_retention_days: 0,
                    processed_retention_hours: 0,
                    quarantine_retention_days: 3,
                    sync_run_retention_days: 2,
                    log_telemetry_activity: true,
                },
            ],
        ]);
        assert.strictEqual(this.owner.lookup('service:notifications').successes.length, 1);
    });
});
