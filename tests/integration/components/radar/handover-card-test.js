import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { setupIntl } from 'ember-intl/test-support';

module('Integration | Component | radar/handover-card', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    test('it lists the orders, the suggested cover and the three actions', async function (assert) {
        this.calls = [];
        this.handover = {
            key: 'shift_handover:driver_ortega',
            rule: 'shift_handover',
            driver: { label: 'Luis Ortega' },
            shift: { uuid: 'sh1', start_at: '2026-09-15T01:15:00Z', end_at: '2026-09-15T09:15:00Z', minutes_left: 40 },
            orders: [
                { uuid: 'o1', public_id: 'order_88412', destination: 'Bay Ridge', ends_at: '2026-09-15T09:40:00Z', finishes_after_shift: true },
                { uuid: 'o2', public_id: 'order_88431', destination: 'Sunset Park', ends_at: '2026-09-15T08:50:00Z', finishes_after_shift: false },
            ],
            suggested: { driver: { uuid: 'driver-alves', label: 'Tomas Alves' }, on_shift_until: '2026-09-15T21:00:00Z', distance_km: 1.2, capacity_label: '2 of 6 orders' },
            record: { route: 'management.drivers.index.details', model: 'driver_ortega' },
        };
        this.onClose = () => this.calls.push('close');
        this.onReassign = (handover) => this.calls.push(['reassign', handover.suggested.driver.uuid]);
        this.onExtend = (handover) => this.calls.push(['extend', handover.shift.uuid]);
        this.onSnooze = (handover) => this.calls.push(['snooze', handover.key]);
        this.onOpenRecord = (record) => this.calls.push(['open', record.model]);

        await render(
            hbs`<Radar::HandoverCard @handover={{this.handover}} @onClose={{this.onClose}} @onReassign={{this.onReassign}} @onExtend={{this.onExtend}} @onSnooze={{this.onSnooze}} @onOpenRecord={{this.onOpenRecord}} />`
        );

        assert.dom('[data-test-radar-handover]').includesText('Luis Ortega');
        assert.dom('[data-test-radar-handover]').includesText('in 40m');
        assert.dom('[data-test-radar-handover-order="order_88412"]').hasClass('is-late');
        assert.dom('[data-test-radar-handover-order="order_88431"]').doesNotHaveClass('is-late');
        assert.dom('[data-test-radar-handover-suggested]').includesText('Tomas Alves');
        assert.dom('[data-test-radar-handover-suggested]').includesText('1.2 km away');
        assert.dom('[data-test-radar-handover-suggested]').includesText('2 of 6 orders');
        assert.dom('[data-test-radar-handover-reassign]').includesText('Reassign 2 orders to Tomas Alves');

        await click('[data-test-radar-handover-reassign]');
        await click('[data-test-radar-handover-extend]');
        await click('[data-test-radar-handover-snooze]');
        await click('[data-test-radar-handover-close]');

        assert.deepEqual(this.calls, [['reassign', 'driver-alves'], ['extend', 'sh1'], ['snooze', 'shift_handover:driver_ortega'], 'close']);

        this.set('handover', { ...this.handover, suggested: null });
        assert.dom('[data-test-radar-handover-reassign]').doesNotExist();
        assert.dom('[data-test-radar-handover-suggested]').includesText('Nobody on shift long enough');
    });
});
