import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | activity/event-selector', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders', async function (assert) {
        // Set any properties with this.set('myProperty', 'value');
        // Handle any actions with this.set('myAction', function(val) { ... });

        await render(hbs`<Activity::EventSelector />`);

        assert.dom().hasText('');

        // Template block usage:
        await render(hbs`
      <Activity::EventSelector>
        template block text
      </Activity::EventSelector>
    `);

        assert.dom().hasText('template block text');
    });
});
