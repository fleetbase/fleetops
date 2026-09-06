import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | order/activity-timeline', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the activity on a timeline and marks the active status', async function (assert) {
        this.set('activity', [
            { status: 'Order created', code: 'created', details: 'Created by dispatcher', created_at: new Date(2026, 8, 1, 9, 5) },
            { status: 'Dispatched', code: 'dispatched', details: null, created_at: new Date(2026, 8, 1, 10, 15) },
        ]);
        this.set('resource', { status: 'dispatched', tracking_statuses: [{ status: 'From resource', code: 'created', created_at: new Date(2026, 8, 2, 8, 0) }] });

        await render(hbs`<Order::ActivityTimeline @activity={{this.activity}} @resource={{this.resource}} />`);

        const items = findAll('.timeline-item');
        assert.strictEqual(items.length, 2);
        assert.dom(items[0]).includesText('Order created');
        assert.dom(items[0]).includesText('Created by dispatcher');
        assert.dom(items[0]).includesText('1 Sep 09:05');
        assert.dom(items[0]).doesNotHaveClass('active');
        assert.dom(items[1]).includesText('Dispatched');
        assert.dom(items[1]).includesText('-', 'missing details fall back to a dash');
        assert.dom(items[1]).hasClass('active', 'the item matching the order status is active');

        await render(hbs`<Order::ActivityTimeline @resource={{this.resource}} />`);
        assert.dom('.timeline-item').exists({ count: 1 });
        assert.dom('.timeline-item').includesText('From resource');
    });

    test('it shows the empty state without activity', async function (assert) {
        this.set('resource', { status: 'created', tracking_statuses: [] });

        await render(hbs`<Order::ActivityTimeline @resource={{this.resource}} />`);

        assert.dom('.timeline').doesNotExist();
        assert.dom().includesText('No order activity');
    });
});
