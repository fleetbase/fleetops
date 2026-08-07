import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Controller | connectivity/telematics/details', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.controller = this.owner.lookup('controller:connectivity/telematics/details');
    });

    test('active and connected both display as connected', function (assert) {
        this.controller.model = { status: 'active' };
        assert.strictEqual(this.controller.statusLabel, 'Connected');
        assert.strictEqual(this.controller.healthStatus, 'success');

        this.controller.model = { status: 'connected' };
        assert.strictEqual(this.controller.statusLabel, 'Connected');
        assert.strictEqual(this.controller.healthStatus, 'success');
    });

    test('remaining provider statuses map to their operator facing labels', function (assert) {
        this.controller.model = { status: 'synchronizing' };
        assert.strictEqual(this.controller.statusLabel, 'Syncing');
        assert.strictEqual(this.controller.healthStatus, 'info');

        this.controller.model = { status: 'initialized' };
        assert.strictEqual(this.controller.statusLabel, 'Not tested');
        assert.strictEqual(this.controller.healthStatus, 'default');

        this.controller.model = { status: 'error' };
        assert.strictEqual(this.controller.statusLabel, 'Needs attention');
        assert.strictEqual(this.controller.healthStatus, 'warning');
    });

    test('a missing status falls back to unknown', function (assert) {
        this.controller.model = {};

        assert.strictEqual(this.controller.statusLabel, 'Unknown');
        assert.strictEqual(this.controller.healthStatus, 'default');
    });

    test('the last sync timestamp follows the sync lifecycle', function (assert) {
        this.controller.model = {
            status: 'synchronizing',
            meta: { last_sync_started_at: '2026-06-18T15:00:00Z', last_sync_completed_at: '2026-06-17T15:00:00Z' },
        };
        assert.strictEqual(this.controller.lastSyncAt, '2026-06-18T15:00:00Z');

        this.controller.model = {
            status: 'connected',
            meta: { last_sync_started_at: '2026-06-18T15:00:00Z', last_sync_completed_at: '2026-06-18T15:04:00Z' },
        };
        assert.strictEqual(this.controller.lastSyncAt, '2026-06-18T15:04:00Z');
    });
});
