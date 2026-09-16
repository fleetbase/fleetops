import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { setupIntl } from 'ember-intl/test-support';

function radarItem(extra = {}) {
    return {
        key: 'inspection_failed:inspection_submission_a',
        rule: 'inspection_failed',
        category: 'inspections',
        severity: 'critical',
        title: 'Pre-trip DVIR failed 2 critical items',
        subject: { type: 'vehicle', public_id: 'vehicle_a', label: 'TRK-207 Isuzu NPR', photo_url: null },
        meta_line: '2 failed · no issue · no work order',
        due_at: null,
        due_bucket: 'none',
        due_label: null,
        record: { route: 'maintenance.inspection-submissions.index.details', model: 'inspection_submission_a' },
        actions: ['create_work_order_from_inspection', 'create_issue_from_inspection', 'acknowledge', 'snooze', 'assign', 'open_record'],
        state: { status: 'open', assigned_to: null },
        ...extra,
    };
}

module('Integration | Component | radar/item-row', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    hooks.beforeEach(function () {
        this.acts = [];
        this.opened = [];
        this.selected = [];
        this.records = [];
        this.onAct = (item, action, payload) => this.acts.push([item.key, action, payload]);
        this.onOpen = (item) => this.opened.push(item.key);
        this.onSelect = (item) => this.selected.push(item.key);
        this.onFocus = () => {};
        this.onOpenRecord = (item) => this.records.push(item.key);
        this.presets = ['1h', '4h', 'tomorrow', 'next-week'];
    });

    test('it renders the chip, severity, title, subject, meta and the rail with the record action first', async function (assert) {
        this.item = radarItem();

        await render(
            hbs`<Radar::ItemRow @item={{this.item}} @snoozePresets={{this.presets}} @onAct={{this.onAct}} @onOpen={{this.onOpen}} @onSelect={{this.onSelect}} @onFocus={{this.onFocus}} @onOpenRecord={{this.onOpenRecord}} />`
        );

        assert.dom('[data-test-radar-row]').hasAttribute('data-radar-key', 'inspection_failed:inspection_submission_a');
        assert.dom('.fleet-ops-radar-row-chip .status-badge').hasClass('violet-status-badge');
        assert.dom('.fleet-ops-radar-severity').hasClass('is-critical');
        assert.dom('[data-test-radar-title]').hasText('Pre-trip DVIR failed 2 critical items');
        assert.dom('[data-test-radar-subject="vehicle"]').includesText('TRK-207 Isuzu NPR');
        assert.dom('.fleet-ops-radar-row-meta').hasText('2 failed · no issue · no work order');
        assert.dom('[data-test-radar-due]').hasText('—');
        assert.dom('[data-test-radar-assignee]').hasClass('is-empty');
        assert.dom('[data-test-radar-action="create_work_order_from_inspection"]').exists('the record action is the primary rail button');
        assert.dom('[data-test-radar-action="acknowledge"]').exists();
        assert.dom('[data-test-radar-action="assign"]').exists();
        assert.dom('[data-test-radar-open-record]').exists();

        await click('[data-test-radar-action="create_work_order_from_inspection"]');
        await click('[data-test-radar-action="acknowledge"]');
        assert.deepEqual(
            this.acts.map(([, action]) => action),
            ['create_work_order_from_inspection', 'acknowledge']
        );

        await click('[data-test-radar-title]');
        assert.deepEqual(this.opened, ['inspection_failed:inspection_submission_a']);

        await click('[data-test-radar-select] input');
        assert.deepEqual(this.selected, ['inspection_failed:inspection_submission_a']);

        await click('[data-test-radar-open-record]');
        assert.deepEqual(this.records, ['inspection_failed:inspection_submission_a']);
    });

    test('an acknowledged row dims and drops the acknowledge button; a snoozed row shows its wake time and a wake button', async function (assert) {
        this.item = radarItem({
            state: { status: 'acknowledged', acknowledged_at: '2026-09-15T08:12:00Z', acknowledged_by_name: 'M. Reyes', assigned_to: { name: 'J. Tran', initials: 'JT' } },
        });

        await render(
            hbs`<Radar::ItemRow @item={{this.item}} @snoozePresets={{this.presets}} @onAct={{this.onAct}} @onOpen={{this.onOpen}} @onSelect={{this.onSelect}} @onFocus={{this.onFocus}} @onOpenRecord={{this.onOpenRecord}} />`
        );

        assert.dom('[data-test-radar-row]').hasClass('is-acknowledged');
        assert.dom('[data-test-radar-state="acknowledged"]').includesText('M. Reyes');
        assert.dom('[data-test-radar-action="acknowledge"]').doesNotExist();
        assert.dom('[data-test-radar-assignee]').includesText('J. Tran');

        this.set('item', radarItem({ state: { status: 'snoozed', snoozed_until: '2026-09-18T08:00:00Z' } }));
        assert.dom('[data-test-radar-row]').hasClass('is-snoozed');
        assert.dom('[data-test-radar-state="snoozed"]').exists();
        assert.dom('[data-test-radar-action="wake"]').exists();

        await click('[data-test-radar-action="wake"]');
        assert.strictEqual(this.acts.at(-1)[1], 'wake');
    });

    test('an overdue row colours its due label and a selected or focused row is marked', async function (assert) {
        this.item = radarItem({ due_bucket: 'overdue', due_label: '3d overdue' });

        await render(
            hbs`<Radar::ItemRow @item={{this.item}} @isSelected={{true}} @isFocused={{true}} @snoozePresets={{this.presets}} @onAct={{this.onAct}} @onOpen={{this.onOpen}} @onSelect={{this.onSelect}} @onFocus={{this.onFocus}} @onOpenRecord={{this.onOpenRecord}} />`
        );

        assert.dom('[data-test-radar-due]').hasClass('is-overdue');
        assert.dom('[data-test-radar-due]').hasText('3d overdue');
        assert.dom('[data-test-radar-row]').hasClass('is-selected');
        assert.dom('[data-test-radar-row]').hasClass('is-focused');
    });
});
