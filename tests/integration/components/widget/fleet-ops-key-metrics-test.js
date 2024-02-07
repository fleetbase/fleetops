import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | widget/fleet-ops-key-metrics', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders', async function (assert) {
        // Set any properties with this.set('myProperty', 'value');
        // Handle any actions with this.set('myAction', function(val) { ... });

        await render(hbs`<Widget::FleetOpsKeyMetrics />`);

        assert.dom().hasText('');

        // Template block usage:
        await render(hbs`
      <Widget::FleetOpsKeyMetrics>
        template block text
      </Widget::FleetOpsKeyMetrics>
    `);

        assert.dom().hasText('template block text');
    });
});
