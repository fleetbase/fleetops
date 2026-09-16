import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { setupIntl } from 'ember-intl/test-support';

module('Integration | Component | radar/filter-pills', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    test('it renders counts, marks active pills and reports toggles and clears', async function (assert) {
        this.toggled = [];
        this.cleared = 0;
        this.pills = [
            { key: 'overdue', label: 'Overdue', count: 3, active: true, dot: true },
            { key: 'due_week', label: 'Due this week', count: 12, active: false },
            { key: 'fuel', label: 'Unmatched fuel', count: 0, active: false },
        ];
        this.onToggle = (key) => this.toggled.push(key);
        this.onClear = () => this.cleared++;

        await render(hbs`<Radar::FilterPills @pills={{this.pills}} @isFiltered={{true}} @onToggle={{this.onToggle}} @onClear={{this.onClear}} />`);

        assert.dom('[data-test-radar-pill="overdue"]').hasClass('is-active');
        assert.dom('[data-test-radar-pill="overdue"]').hasAttribute('aria-pressed', 'true');
        assert.dom('[data-test-radar-pill="overdue"] .fleet-ops-radar-pill-dot').exists();
        assert.dom('[data-test-radar-pill="overdue"] .fleet-ops-radar-pill-count').hasText('3');
        assert.dom('[data-test-radar-pill="due_week"]').doesNotHaveClass('is-active');
        assert.dom('[data-test-radar-pill="fuel"]').hasClass('is-empty');
        assert.dom('[data-test-radar-pills-clear]').exists();

        await click('[data-test-radar-pill="due_week"]');
        assert.deepEqual(this.toggled, ['due_week']);

        await click('[data-test-radar-pills-clear]');
        assert.strictEqual(this.cleared, 1);

        this.set(
            'pills',
            this.pills.map((pill) => ({ ...pill, active: false }))
        );
        this.set('isFiltered', false);
        await render(hbs`<Radar::FilterPills @pills={{this.pills}} @isFiltered={{false}} @onToggle={{this.onToggle}} @onClear={{this.onClear}} />`);
        assert.dom('[data-test-radar-pills-clear]').doesNotExist();
    });
});
