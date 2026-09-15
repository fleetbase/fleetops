import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { setupIntl } from 'ember-intl/test-support';

module('Integration | Component | radar/view-bar', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    test('it renders the status tabs with counts and the saved views', async function (assert) {
        this.statuses = [];
        this.applied = [];
        this.deleted = [];
        this.saves = 0;
        this.stats = { open: 32, snoozed: 6 };
        this.views = [
            { id: 'my-assignments', label: 'My assignments', isDefault: true },
            { id: 'view-1', label: 'Unmatched fuel' },
        ];
        this.activeView = this.views[1];
        this.onSetStatus = (status) => this.statuses.push(status);
        this.onApplyView = (view) => this.applied.push(view.id);
        this.onDeleteView = (view) => this.deleted.push(view.id);
        this.onSaveView = () => this.saves++;

        await render(
            hbs`<Radar::ViewBar @status="open" @stats={{this.stats}} @views={{this.views}} @activeView={{this.activeView}} @onSetStatus={{this.onSetStatus}} @onApplyView={{this.onApplyView}} @onSaveView={{this.onSaveView}} @onDeleteView={{this.onDeleteView}} />`
        );

        assert.dom('[data-test-radar-tab="open"]').hasClass('is-active');
        assert.dom('[data-test-radar-tab="open"] .fleet-ops-radar-tab-count').hasText('32');
        assert.dom('[data-test-radar-tab="snoozed"] .fleet-ops-radar-tab-count').hasText('6');
        assert.dom('[data-test-radar-tab="resolved"] .fleet-ops-radar-tab-count').doesNotExist();
        assert.dom('[data-test-radar-view-item="view-1"]').hasClass('is-active');
        assert.dom('[data-test-radar-view-delete="my-assignments"]').doesNotExist('default views cannot be deleted');
        assert.dom('[data-test-radar-view-delete="view-1"]').exists();

        await click('[data-test-radar-tab="snoozed"]');
        assert.deepEqual(this.statuses, ['snoozed']);

        await click('[data-test-radar-view-apply="my-assignments"]');
        assert.deepEqual(this.applied, ['my-assignments']);

        await click('[data-test-radar-view-delete="view-1"]');
        assert.deepEqual(this.deleted, ['view-1']);
        assert.deepEqual(this.applied, ['my-assignments'], 'deleting does not also apply the view');

        await click('[data-test-radar-view-save]');
        assert.strictEqual(this.saves, 1);
    });
});
