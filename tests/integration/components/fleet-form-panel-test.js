import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | fleet-form-panel', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders', async function (assert) {
        // Set any properties with this.set('myProperty', 'value');
        // Handle any actions with this.set('myAction', function(val) { ... });

        await render(hbs`<FleetFormPanel />`);

        assert.dom(this.element).hasText('');

        // Template block usage:
        await render(hbs`
      <FleetFormPanel>
        template block text
      </FleetFormPanel>
    `);

        assert.dom(this.element).hasText('template block text');
    });
});
