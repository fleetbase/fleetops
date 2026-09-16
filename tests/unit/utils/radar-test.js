import { module, test } from 'qunit';
import { chipStatusFor, primaryActionFor, secondaryActionsFor, snoozePayloadFor, initialsOf, patchPayload } from '@fleetbase/fleetops-engine/utils/radar';

module('Unit | Utility | radar', function () {
    test('chipStatusFor maps categories and rules to badge statuses, issues by severity', function (assert) {
        assert.strictEqual(chipStatusFor({ rule: 'maintenance_overdue', category: 'maintenance' }), 'warning');
        assert.strictEqual(chipStatusFor({ rule: 'work_order_overdue', category: 'maintenance' }), 'orange');
        assert.strictEqual(chipStatusFor({ rule: 'inspection_failed', category: 'inspections' }), 'violet');
        assert.strictEqual(chipStatusFor({ rule: 'shift_handover', category: 'staffing' }), 'indigo');
        assert.strictEqual(chipStatusFor({ rule: 'driver_without_vehicle', category: 'staffing' }), 'info');
        assert.strictEqual(chipStatusFor({ rule: 'issue_open', category: 'issues', severity: 'critical' }), 'error');
        assert.strictEqual(chipStatusFor({ rule: 'issue_open', category: 'issues', severity: 'warning' }), 'warning');
        assert.strictEqual(chipStatusFor({ rule: 'notice', category: 'notices' }), 'gray');
        assert.strictEqual(chipStatusFor(null), 'gray');
    });

    test('primaryActionFor picks the first record-level action and secondaryActionsFor the rest', function (assert) {
        const item = { actions: ['create_work_order_from_inspection', 'create_issue_from_inspection', 'acknowledge', 'snooze', 'assign', 'open_record'] };

        assert.deepEqual(primaryActionFor(item), { key: 'create_work_order_from_inspection', label: 'radar.actions.create-work-order', icon: 'clipboard-list' });
        assert.deepEqual(
            secondaryActionsFor(item).map((action) => action.key),
            ['create_issue_from_inspection']
        );
        assert.strictEqual(primaryActionFor({ actions: ['acknowledge', 'snooze'] }), null);
        assert.strictEqual(primaryActionFor(null), null);
    });

    test('snoozePayloadFor gives minutes for short presets and a next-morning date for long ones', function (assert) {
        const now = new Date('2026-09-15T08:35:00');

        assert.deepEqual(snoozePayloadFor('1h', now), { minutes: 60 });
        assert.deepEqual(snoozePayloadFor('4h', now), { minutes: 240 });

        const tomorrow = new Date(snoozePayloadFor('tomorrow', now).until);
        assert.strictEqual(tomorrow.getDate(), 16);
        assert.strictEqual(tomorrow.getHours(), 8);

        const nextWeek = new Date(snoozePayloadFor('next-week', now).until);
        assert.strictEqual(nextWeek.getDate(), 22);
        assert.deepEqual(snoozePayloadFor('unknown', now), { minutes: 60 });
    });

    test('initialsOf takes the first and last name', function (assert) {
        assert.strictEqual(initialsOf('Ada Ops'), 'AO');
        assert.strictEqual(initialsOf('Cher'), 'C');
        assert.strictEqual(initialsOf('  mary jane watson '), 'MW');
        assert.strictEqual(initialsOf(null), '');
    });

    test('patchPayload replaces or drops one item in both the flat list and the groups', function (assert) {
        const payload = {
            items: [
                { key: 'a', state: { status: 'open' } },
                { key: 'b', state: { status: 'open' } },
            ],
            groups: [
                { key: 'overdue', count: 1, items: [{ key: 'a', state: { status: 'open' } }] },
                { key: 'none', count: 1, items: [{ key: 'b', state: { status: 'open' } }] },
            ],
        };

        const patched = patchPayload(payload, 'a', (item) => ({ ...item, state: { status: 'acknowledged' } }));
        assert.strictEqual(patched.items[0].state.status, 'acknowledged');
        assert.strictEqual(patched.groups[0].items[0].state.status, 'acknowledged');
        assert.strictEqual(payload.items[0].state.status, 'open', 'the original payload is untouched');

        const dropped = patchPayload(payload, 'a', () => null);
        assert.deepEqual(
            dropped.items.map((item) => item.key),
            ['b']
        );
        assert.deepEqual(
            dropped.groups.map((group) => group.key),
            ['none'],
            'an emptied group disappears'
        );
        assert.strictEqual(
            patchPayload(null, 'a', () => null),
            null
        );
    });
});
