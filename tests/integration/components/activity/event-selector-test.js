import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | activity/event-selector', function (hooks) {
    setupRenderingTest(hooks);

    test('it lists the selected events and lets more be added or removed', async function (assert) {
        const changes = [];
        this.set('activity', { events: ['order.failed'] });
        this.set('onChange', (events) => changes.push(events));

        await render(hbs`<Activity::EventSelector @activity={{this.activity}} @onChange={{this.onChange}} />`);

        assert.dom('.activity-event-selector-event').exists({ count: 1 });
        assert.dom('.activity-event-selector-event').includesText('order.failed');
        assert.dom().doesNotIncludeText('No activity events');

        await click('.ember-basic-dropdown-trigger');
        assert.dom('.next-dd-item').exists({ count: 4 });
        assert.deepEqual(
            findAll('.next-dd-item .font-mono').map((element) => element.textContent.trim()),
            ['order.dispatched', 'order.failed', 'order.canceled', 'order.completed']
        );
        assert.dom(findAll('.next-dd-item')[3]).includesText('Triggers when an order is completed by a driver, or system process.');

        await click(findAll('.next-dd-item')[0]);
        assert.deepEqual(changes, [['order.dispatched', 'order.failed']]);
        assert.deepEqual(
            findAll('.activity-event-selector-event > span:first-child').map((element) => element.textContent.trim()),
            ['order.dispatched', 'order.failed']
        );

        await click(findAll('.activity-event-selector-event button')[1]);
        assert.deepEqual(changes.at(-1), ['order.dispatched']);
        assert.dom('.activity-event-selector-event').exists({ count: 1 });
    });

    test('without events it shows the empty state, and without onChange it still updates', async function (assert) {
        this.set('activity', {});

        await render(hbs`<Activity::EventSelector @activity={{this.activity}} />`);

        assert.dom().includesText('No activity events');
        assert.dom('.activity-event-selector-event').doesNotExist();

        await click('.ember-basic-dropdown-trigger');
        await click(findAll('.next-dd-item')[2]);
        assert.dom('.activity-event-selector-event').hasText('order.canceled');
        assert.dom().doesNotIncludeText('No activity events');

        await click('.activity-event-selector-event button');
        assert.dom().includesText('No activity events');
    });
});
