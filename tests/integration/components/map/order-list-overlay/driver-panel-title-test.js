import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | map/order-list-overlay/driver-panel-title', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the driver avatar, name and active order count', async function (assert) {
        this.set('context', { vehicle_avatar: 'https://cdn.example.com/van.png', name: 'Sam Driver', _panelActiveJobs: [{}, {}] });

        await render(hbs`<Map::OrderListOverlay::DriverPanelTitle @context={{this.context}} />`);

        assert.dom('img').hasAttribute('src', 'https://cdn.example.com/van.png');
        assert.dom('img').hasAttribute('alt', 'Sam Driver');
        assert.dom('.text-sm').hasText('Sam Driver');
        assert.dom('.resource-count').hasText('2 Orders');

        this.set('context', { ...this.context, _panelActiveJobs: [{}] });
        assert.dom('.resource-count').hasText('1 Order');
    });
});
