import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Helper | get-fleet-ops-options', function (hooks) {
    setupRenderingTest(hooks);

    test('it returns work order lifecycle statuses', async function (assert) {
        await render(hbs`
            {{#each (get-fleet-ops-options "workOrderStatuses") as |status|}}
                {{status.value}}:{{status.label}};
            {{/each}}
        `);

        assert.dom().containsText('open:Open');
        assert.dom().containsText('closed:Closed');
        assert.dom().containsText('canceled:Canceled');
    });

    test('it returns work order operational categories', async function (assert) {
        await render(hbs`
            {{#each (get-fleet-ops-options "workOrderCategories") as |category|}}
                {{category.value}}:{{category.label}};
            {{/each}}
        `);

        assert.dom().containsText('preventive_maintenance:Preventive Maintenance (PM)');
        assert.dom().containsText('tire_issue:Tire Issue');
        assert.dom().containsText('breakdown:Breakdown');
    });
});
