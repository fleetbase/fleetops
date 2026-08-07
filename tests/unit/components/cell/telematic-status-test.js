import { module, test } from 'qunit';
import CellTelematicStatusComponent from 'dummy/components/cell/telematic-status';

function makeStatusCell(status) {
    const component = Object.create(CellTelematicStatusComponent.prototype);
    component.args = { row: { status } };

    return component;
}

module('Unit | Component | cell/telematic-status', function () {
    test('active and connected both display as connected', function (assert) {
        assert.strictEqual(makeStatusCell('active').label, 'Connected');
        assert.strictEqual(makeStatusCell('connected').label, 'Connected');
        assert.strictEqual(makeStatusCell('active').badgeStatus, 'success');
        assert.strictEqual(makeStatusCell('connected').badgeStatus, 'success');
    });

    test('remaining provider statuses map to their operator facing labels', function (assert) {
        assert.strictEqual(makeStatusCell('synchronizing').label, 'Syncing');
        assert.strictEqual(makeStatusCell('synchronizing').badgeStatus, 'info');
        assert.strictEqual(makeStatusCell('initialized').label, 'Not tested');
        assert.strictEqual(makeStatusCell('error').label, 'Needs attention');
        assert.strictEqual(makeStatusCell('error').badgeStatus, 'warning');
        assert.strictEqual(makeStatusCell('degraded').badgeStatus, 'warning');
        assert.strictEqual(makeStatusCell('disconnected').badgeStatus, 'warning');
    });

    test('a missing status falls back to unknown', function (assert) {
        assert.strictEqual(makeStatusCell(undefined).label, 'Unknown');
        assert.strictEqual(makeStatusCell(undefined).badgeStatus, 'default');
    });
});
