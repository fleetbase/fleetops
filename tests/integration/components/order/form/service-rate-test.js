import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | order/form/service-rate', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders', async function (assert) {
        // Set any properties with this.set('myProperty', 'value');
        // Handle any actions with this.set('myAction', function(val) { ... });

        await render(hbs`<Order::Form::ServiceRate />`);

        assert.dom().hasText('');

        // Template block usage:
        await render(hbs`
      <Order::Form::ServiceRate>
        template block text
      </Order::Form::ServiceRate>
    `);

        assert.dom().hasText('template block text');
    });

    test('service rate selector is searchable', async function (assert) {
        this.set('resource', {
            servicable: true,
            order_config: {},
            payloadCoordinates: ['1,1', '2,2'],
            payload: {
                payloadCoordinates: ['1,1', '2,2'],
            },
        });

        await render(hbs`<Order::Form::ServiceRate @resource={{this.resource}} />`);
        await click('.ember-power-select-trigger');

        assert.dom('.ember-power-select-search-input').exists();
    });
});
