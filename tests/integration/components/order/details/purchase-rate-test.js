import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | order/details/purchase-rate', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the purchased quote breakdown and total', async function (assert) {
        this.set('resource', {
            purchase_rate: {
                service_quote: {
                    currency: 'USD',
                    amount: 1500,
                    items: [
                        { details: 'Base fare', amount: 1000 },
                        { details: 'Fuel surcharge', amount: 500 },
                    ],
                },
            },
        });

        await render(hbs`<Order::Details::PurchaseRate @resource={{this.resource}} @isLoading={{false}} />`);

        assert.dom('.panel-title').hasText('Purchase Rate');
        assert.deepEqual(
            findAll('thead th').map((th) => th.textContent.trim()),
            ['Breakdown', 'USD']
        );
        assert.deepEqual(
            findAll('tbody tr').map((row) => [...row.querySelectorAll('td')].map((td) => td.textContent.trim())),
            [
                ['Base fare', '$10.00'],
                ['Fuel surcharge', '$5.00'],
            ]
        );
        assert.deepEqual(
            findAll('tfoot td').map((td) => td.textContent.trim()),
            ['Total', '$15.00']
        );
    });

    test('it renders nothing without a purchase rate', async function (assert) {
        this.set('resource', { purchase_rate: null });

        await render(hbs`<Order::Details::PurchaseRate @resource={{this.resource}} />`);

        assert.dom('.panel-title').doesNotExist();
        assert.dom('table').doesNotExist();
    });
});
