import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | order/panel-header', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the order summary with the panel header actions', async function (assert) {
        const calls = [];
        this.set('resource', {
            public_id: 'order_1',
            tracking: 'FLB-1',
            status: 'dispatched',
            type: 'transport',
            dispatched_at: new Date(2026, 8, 1),
            dispatchedAt: '1 Sep 2026',
            createdAt: '31 Aug 2026',
            tracking_number: { qr_code: 'QRDATA' },
        });
        this.set('actionButtons', [{ text: 'Refresh', icon: 'sync', onClick: () => calls.push('refresh') }]);
        this.set('onPressCancel', () => calls.push('cancel'));

        await render(hbs`<Order::PanelHeader @resource={{this.resource}} @actionButtons={{this.actionButtons}} @onPressCancel={{this.onPressCancel}} />`);

        assert.dom('img').hasAttribute('src', 'data:image/png;base64,QRDATA');
        assert.dom('img').hasAttribute('alt', 'order_1');
        assert.dom('.font-semibold').hasText('FLB-1');
        assert.dom().includesText('Dispatched at 1 Sep 2026');
        assert.dom().includesText('Date Created: 31 Aug 2026');
        assert.dom().includesText('Type: Transport');

        await click(findAll('button').find((button) => /Refresh/.test(button.textContent)));
        await click('.next-content-overlay-panel-cancel-button');
        assert.deepEqual(calls, ['refresh', 'cancel']);

        this.set('resource', { ...this.resource, dispatched_at: null });
        assert.dom().doesNotIncludeText('Dispatched at');
    });
});
