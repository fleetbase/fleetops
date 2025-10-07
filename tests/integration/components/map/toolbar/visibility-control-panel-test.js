import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | map/toolbar/visibility-control-panel', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders', async function (assert) {
        // Set any properties with this.set('myProperty', 'value');
        // Handle any actions with this.set('myAction', function(val) { ... });

        await render(hbs`<Map::Toolbar::VisibilityControlPanel />`);

        assert.dom().hasText('');

        // Template block usage:
        await render(hbs`
      <Map::Toolbar::VisibilityControlPanel>
        template block text
      </Map::Toolbar::VisibilityControlPanel>
    `);

        assert.dom().hasText('template block text');
    });
});
