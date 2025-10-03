import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | vehicle/card', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders', async function (assert) {
        // Set any properties with this.set('myProperty', 'value');
        // Handle any actions with this.set('myAction', function(val) { ... });

        await render(hbs`<Vehicle::Card />`);

        assert.dom().hasText('');

        // Template block usage:
        await render(hbs`
      <Vehicle::Card>
        template block text
      </Vehicle::Card>
    `);

        assert.dom().hasText('template block text');
    });
});
