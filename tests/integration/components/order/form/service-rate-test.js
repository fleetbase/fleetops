import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import Service from '@ember/service';
import { click, render, waitUntil } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import OrderFormServiceRateComponent from '@fleetbase/fleetops-engine/components/order/form/service-rate';

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

    test('service quote refresh events run debounced quote lookup for the matching order', async function (assert) {
        const calls = [];
        const resource = {
            servicable: true,
            service_quote_uuid: 'stale-quote',
            payloadCoordinates: ['1,1', '2,2'],
            payload: {
                payloadCoordinates: ['1,1', '2,2'],
            },
        };
        const selectedRate = { id: 'rate-1' };

        class ServiceRateActionsStub extends Service {
            getServiceQuotes = {
                perform(serviceRate, order) {
                    calls.push({ serviceRate, order });
                    return Promise.resolve([{ uuid: 'fresh-quote' }]);
                },
            };
        }

        this.owner.register('service:service-rate-actions', ServiceRateActionsStub);

        const orderCreation = this.owner.lookup('service:order-creation');
        const component = new OrderFormServiceRateComponent(this.owner, { resource });
        component.selectedRate = selectedRate;

        orderCreation.requestServiceQuoteRefresh('entity.added', { id: 'other-order' });
        orderCreation.requestServiceQuoteRefresh('entity.added', resource);

        await waitUntil(() => calls.length === 1, { timeout: 1000 });

        assert.strictEqual(calls.length, 1, 'refreshes once after debounce');
        assert.strictEqual(calls[0].serviceRate, selectedRate);
        assert.strictEqual(calls[0].order, resource);
        assert.strictEqual(resource.service_quote_uuid, null, 'clears stale selected quote');

        component.willDestroy();
    });

    test('service quote refresh events are ignored until a rate is selected', async function (assert) {
        const calls = [];
        const resource = {
            servicable: true,
            payloadCoordinates: ['1,1', '2,2'],
            payload: {
                payloadCoordinates: ['1,1', '2,2'],
            },
        };

        class ServiceRateActionsStub extends Service {
            getServiceQuotes = {
                perform(serviceRate, order) {
                    calls.push({ serviceRate, order });
                    return Promise.resolve([]);
                },
            };
        }

        this.owner.register('service:service-rate-actions', ServiceRateActionsStub);

        const orderCreation = this.owner.lookup('service:order-creation');
        const component = new OrderFormServiceRateComponent(this.owner, { resource });

        orderCreation.requestServiceQuoteRefresh('entity.added', resource);

        await new Promise((resolve) => setTimeout(resolve, 600));

        assert.strictEqual(calls.length, 0);

        component.willDestroy();
    });
});
