import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | order/activity-list', function (hooks) {
    setupRenderingTest(hooks);

    test('it lists the given activity, falling back to the order tracking statuses', async function (assert) {
        this.set('activity', [
            { status: 'Order created', details: 'Created by dispatcher', created_at: new Date(2026, 8, 1, 9, 5) },
            { status: 'Dispatched', details: 'Driver notified', created_at: new Date(2026, 8, 1, 10, 15) },
        ]);
        this.set('resource', { tracking_statuses: [{ status: 'From resource', details: 'fallback', created_at: new Date(2026, 8, 2, 8, 0) }] });

        await render(hbs`<Order::ActivityList @activity={{this.activity}} @resource={{this.resource}} />`);

        const items = findAll('.order-activity-list-item').map((el) => el.textContent.replace(/\s+/g, ' ').trim());
        assert.deepEqual(items, ['Order created Created by dispatcher 1 Sep 2026 09:05', 'Dispatched Driver notified 1 Sep 2026 10:15']);

        await render(hbs`<Order::ActivityList @resource={{this.resource}} />`);
        assert.dom('.order-activity-list-item').exists({ count: 1 });
        assert.dom('.order-activity-list-item').includesText('From resource');
    });

    test('it shows the empty state without activity', async function (assert) {
        await render(hbs`<Order::ActivityList @activity={{this.none}} @resource={{this.none}} />`);

        assert.dom('.order-activity-list-item').doesNotExist();
        assert.dom().includesText('No order activity');
        assert.dom().includesText('Dispatch or update the order to create activity');
    });
});
