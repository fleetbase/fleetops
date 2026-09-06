import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | order/customer-avatar-stack', function (hooks) {
    setupRenderingTest(hooks);

    test('it stacks one avatar per waypoint customer with size and overlap classes', async function (assert) {
        this.set('waypoints', [
            { address: '1 First St', customer: { public_id: 'contact_1', name: 'Ada', phone: '+1', photo_url: '/ada.png' } },
            null,
            { address: '2 Second Ave', customer: { id: 'c2', name: 'Bob' } },
            { address: '3 Third Rd' },
        ]);

        await render(hbs`<Order::CustomerAvatarStack @waypoints={{this.waypoints}} @size="lg" @overlap="dense" />`);

        const images = findAll('img');
        assert.strictEqual(images.length, 3, 'null waypoints are skipped, customerless ones still get an avatar');
        assert.deepEqual(
            images.map((img) => img.getAttribute('alt')),
            ['Ada', 'Bob', 'Unknown customer']
        );
        assert.dom(images[0]).hasClass('w-12');
        assert.dom(images[0].parentElement).doesNotHaveClass('-ml-4', 'the first avatar does not overlap');
        assert.dom(images[1].parentElement).hasClass('-ml-4');
        assert.dom().includesText('No Customer');
        assert.dom().includesText('No Phone');
        assert.dom().includesText('3 Third Rd');
    });

    test('unknown sizes and overlaps fall back, and no waypoints render nothing', async function (assert) {
        this.set('waypoints', [{ customer: { name: 'Ada' } }, { customer: { name: 'Bob' } }]);
        await render(hbs`<Order::CustomerAvatarStack @waypoints={{this.waypoints}} @size="huge" @overlap="weird" />`);
        assert.dom('img').hasClass('w-7');
        assert.dom(findAll('img')[1].parentElement).hasClass('-ml-2');

        await render(hbs`<Order::CustomerAvatarStack />`);
        assert.dom('img').doesNotExist();
    });
});
