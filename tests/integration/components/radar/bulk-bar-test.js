import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { setupIntl } from 'ember-intl/test-support';

module('Integration | Component | radar/bulk-bar', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    test('it summarises the selection by chip and fires the bulk actions', async function (assert) {
        this.acts = [];
        this.cleared = 0;
        this.items = [
            { key: 'a', rule: 'maintenance_overdue', state: { status: 'open' } },
            { key: 'b', rule: 'maintenance_due_soon', state: { status: 'open' } },
            { key: 'c', rule: 'work_order_overdue', state: { status: 'snoozed' } },
        ];
        this.presets = ['1h', '4h'];
        this.onAct = (action, payload) => this.acts.push([action, payload]);
        this.onClear = () => this.cleared++;

        await render(hbs`<Radar::BulkBar @items={{this.items}} @snoozePresets={{this.presets}} @onAct={{this.onAct}} @onClear={{this.onClear}} />`);

        assert.dom('[data-test-radar-bulk-count]').hasText('3 selected');
        assert.dom('.fleet-ops-radar-bulk-copy .fleet-ops-radar-row-meta').hasText('2 maint · 1 work order');
        assert.dom('[data-test-radar-bulk-action="wake"]').exists('a snoozed item in the selection offers wake');

        await click('[data-test-radar-bulk-action="acknowledge"]');
        await click('[data-test-radar-bulk-action="assign"]');
        assert.deepEqual(
            this.acts.map(([action]) => action),
            ['acknowledge', 'assign']
        );

        await click('[data-test-radar-bulk-clear]');
        assert.strictEqual(this.cleared, 1);

        this.set('items', [{ key: 'a', rule: 'issue_open', state: { status: 'open' } }]);
        assert.dom('[data-test-radar-bulk-action="wake"]').doesNotExist();
    });
});
