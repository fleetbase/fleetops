import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | device/form', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders', async function (assert) {
        // Set any properties with this.set('myProperty', 'value');
        // Handle any actions with this.set('myAction', function(val) { ... });

        await render(hbs`<Device::Form />`);

        assert.dom().hasText('');

        // Template block usage:
        await render(hbs`
      <Device::Form>
        template block text
      </Device::Form>
    `);

        assert.dom().hasText('template block text');
    });
});
