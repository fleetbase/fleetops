import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | order/pill', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the order QR code, tracking, badges and dates from either argument', async function (assert) {
        const clicked = [];
        const order = {
            public_id: 'order_1',
            tracking: 'FLB-1',
            status: 'dispatched',
            type: 'transport',
            dispatched_at: new Date(2026, 8, 1),
            dispatchedAt: '1 Sep 2026',
            createdAt: '31 Aug 2026',
            tracking_number: { qr_code: 'QRDATA' },
        };
        this.set('order', order);
        this.set('onClick', (resource) => clicked.push(resource.public_id));

        await render(hbs`<Order::Pill @order={{this.order}} @onClick={{this.onClick}} class="probe" />`);

        assert.dom('.fleetbase-pill').hasClass('probe');
        assert.dom('img').hasAttribute('src', 'data:image/png;base64,QRDATA');
        assert.dom('img').hasAttribute('alt', 'order_1');
        assert.dom('.font-semibold').hasText('FLB-1');
        assert.dom().includesText('Dispatched at 1 Sep 2026');
        assert.dom().includesText('Date Created: 31 Aug 2026');
        assert.dom().includesText('Type: Transport');
        assert.dom('.status-badge').exists({ count: 2 });

        await click('.fleetbase-pill a');
        assert.deepEqual(clicked, ['order_1']);

        this.set('resource', { ...order, tracking: null, dispatched_at: null });
        await render(hbs`<Order::Pill @resource={{this.resource}} />`);
        assert.dom('.font-semibold').hasText('-');
        assert.dom().doesNotIncludeText('Dispatched at');
        assert.dom('.status-badge').exists({ count: 1 });
    });
});
