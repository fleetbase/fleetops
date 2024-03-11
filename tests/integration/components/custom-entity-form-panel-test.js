import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | custom-entity-form-panel', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders', async function (assert) {
        // Set any properties with this.set('myProperty', 'value');
        // Handle any actions with this.set('myAction', function(val) { ... });

        await render(hbs`<CustomEntityFormPanel />`);

        assert.dom().hasText('');

        // Template block usage:
        await render(hbs`
      <CustomEntityFormPanel>
        template block text
      </CustomEntityFormPanel>
    `);

        assert.dom().hasText('template block text');
    });
});
