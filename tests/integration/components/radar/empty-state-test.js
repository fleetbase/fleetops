import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { setupIntl } from 'ember-intl/test-support';

module('Integration | Component | radar/empty-state', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    test('an all-clear open tab shows the snooze schedule', async function (assert) {
        this.schedule = [
            { key: 'a', title: 'Tire rotation', snoozed_until: '2026-09-18T08:00:00Z' },
            { key: 'b', title: 'Warranty', snoozed_until: '2026-09-19T08:00:00Z' },
        ];

        await render(hbs`<Radar::EmptyState @status="open" @isFiltered={{false}} @snoozeSchedule={{this.schedule}} />`);

        assert.dom('[data-test-radar-empty="open"]').exists();
        assert.dom('.fleet-ops-radar-empty-title').hasText('Nothing needs a decision.');
        assert.dom('.fleet-ops-radar-empty-hint').includesText('2 snoozed items wake this week.');
        assert.dom('[data-test-radar-snooze-schedule] > div').exists({ count: 2 });
        assert.dom('[data-test-radar-snooze-schedule]').includesText('Tire rotation');
    });

    test('a filtered list, the snoozed tab and the resolved tab each get their own copy', async function (assert) {
        this.cleared = 0;
        this.onClear = () => this.cleared++;

        await render(hbs`<Radar::EmptyState @status="open" @isFiltered={{true}} @snoozeSchedule={{(array)}} @onClear={{this.onClear}} />`);
        assert.dom('[data-test-radar-empty="filtered"]').exists();
        assert.dom('.fleet-ops-radar-empty-title').hasText('Nothing matches these filters.');
        assert.dom('[data-test-radar-snooze-schedule]').doesNotExist();

        await render(hbs`<Radar::EmptyState @status="snoozed" @isFiltered={{false}} @snoozeSchedule={{(array)}} />`);
        assert.dom('.fleet-ops-radar-empty-title').hasText('Nothing is snoozed.');

        await render(hbs`<Radar::EmptyState @status="resolved" @isFiltered={{false}} @snoozeSchedule={{(array)}} />`);
        assert.dom('.fleet-ops-radar-empty-title').hasText('Nothing resolved in the last 7 days.');
    });
});
