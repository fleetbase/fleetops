import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import Service from '@ember/service';
import { click, findAll, render, settled, waitUntil } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { tracked } from '@glimmer/tracking';

const COORDINATES = [
    [103.8845049, 1.3621663],
    [103.86458, 1.353151],
];

// The component assigns `servicable` and `service_quote_uuid` directly, as it would on an Ember Data
// record whose attributes are tracked; a plain object would never re-render.
class OrderFixture {
    static modelName = 'order';
    isNew = true;
    @tracked servicable = false;
    @tracked service_quote_uuid = null;
    @tracked payloadCoordinates = [];
    @tracked payload = null;
    @tracked facilitator = null;
    @tracked order_config = null;

    constructor(attributes) {
        Object.assign(this, attributes);
    }

    set(key, value) {
        this[key] = value;
        return value;
    }

    setProperties(values) {
        Object.assign(this, values);
        return values;
    }
}

function refreshButton() {
    return findAll('button').find((button) => /Refresh/.test(button.textContent));
}

module('Integration | Component | order/form/service-rate', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const test = this;
        this.rateCalls = [];
        this.quoteCalls = [];
        this.rates = [
            { id: 'rate_local', service_name: 'Local Route Rate' },
            { id: 'rate_express', service_name: 'Express Rate' },
        ];
        this.quotes = [];
        this.pendingQuotes = null;
        this.allowWrite = true;
        this.owner.register(
            'service:abilities',
            class extends Service {
                can() {
                    return test.allowWrite;
                }

                cannot() {
                    return !test.allowWrite;
                }
            }
        );
        this.owner.register(
            'service:service-rate-actions',
            class extends Service {
                queryServiceRatesForOrder = {
                    perform: async (order) => {
                        test.rateCalls.push(order);
                        return test.rates;
                    },
                };

                getServiceQuotes = {
                    perform: (serviceRate, order) => {
                        test.quoteCalls.push({ serviceRate, order });
                        if (test.pendingQuotes) {
                            return test.pendingQuotes;
                        }
                        return Promise.resolve(test.quotes);
                    },
                };
            }
        );
        this.orderCreation = this.owner.lookup('service:order-creation');
        this.makeOrder = (attributes = {}) =>
            new OrderFixture({ id: 'order_1', order_config: { id: 'config_1' }, payloadCoordinates: COORDINATES, payload: { payloadCoordinates: COORDINATES }, ...attributes });
    });

    async function selectRate(index = 0) {
        await click('.ember-power-select-trigger');
        await click(findAll('.ember-power-select-option')[index]);
    }

    test('the toggle loads the service rates and a selected rate fetches its quotes', async function (assert) {
        this.quotes = [
            {
                uuid: 'quote_1',
                public_id: 'sq_1',
                service_rate_name: 'Local Route Rate',
                request_id: 'req_1',
                currency: 'USD',
                amount: 1500,
                items: [
                    { details: 'Base fare', amount: 1000 },
                    { details: 'Distance', amount: 500 },
                ],
            },
            { uuid: 'quote_2', public_id: 'sq_2', service_rate_name: 'Local Route Rate', request_id: 'req_2', currency: 'USD', amount: 2000, items: [] },
        ];
        this.set('resource', this.makeOrder());

        await render(hbs`<Order::Form::ServiceRate @resource={{this.resource}} />`);

        assert.dom().includesText('Apply service rate');
        assert.dom('[role="checkbox"]').hasAttribute('aria-checked', 'false');
        assert.dom('[role="checkbox"]').doesNotHaveAttribute('data-disabled');
        assert.dom('.ember-power-select-trigger').doesNotExist();

        await click('[role="checkbox"]');
        assert.true(this.resource.servicable);
        assert.deepEqual(this.rateCalls, [this.resource]);
        assert.dom('.ember-power-select-trigger').exists();
        assert.ok(refreshButton().disabled, 'refresh waits for a rate');
        assert.dom().includesText('Select a real time service quote to apply to this order.');
        assert.dom().includesText('No service quotes.');

        await click('.ember-power-select-trigger');
        assert.dom('.ember-power-select-search-input').exists('the selector is searchable');
        assert.deepEqual(
            findAll('.ember-power-select-option').map((option) => option.textContent.trim()),
            ['Local Route Rate', 'Express Rate']
        );
        await click(findAll('.ember-power-select-option')[0]);

        assert.deepEqual(this.quoteCalls, [{ serviceRate: this.rates[0], order: this.resource }]);
        assert.dom('.radio-group-item').exists({ count: 2 });
        assert.dom().includesText('sq_1 (Local Route Rate)');
        assert.dom().includesText('req_1');
        assert.dom().includesText('Base fare');
        assert.dom().includesText('$10.00');
        assert.dom().includesText('$15.00');
        assert.notOk(refreshButton().disabled);

        await click('input[type="radio"][value="quote_2"]');
        assert.strictEqual(this.resource.service_quote_uuid, 'quote_2');
        assert.dom(findAll('.radio-group-item')[1]).hasClass('is-checked');

        await click(refreshButton());
        assert.strictEqual(this.quoteCalls.length, 2, 'refresh re-fetches with the selected rate');
        assert.strictEqual(this.resource.service_quote_uuid, 'quote_2', 'a still-offered quote stays selected');

        this.quotes = [{ uuid: 'quote_3', public_id: 'sq_3', service_rate_name: 'Local Route Rate', request_id: 'req_3', currency: 'USD', amount: 100, items: [] }];
        await click(refreshButton());
        assert.strictEqual(this.resource.service_quote_uuid, null, 'a quote no longer offered is cleared');

        await click('input[type="radio"][value="quote_3"]');
        this.quotes = null;
        await click(refreshButton());
        assert.strictEqual(this.resource.service_quote_uuid, null, 'no quotes at all clears the selection');
        assert.dom().includesText('No service quotes.');

        await click('[role="checkbox"]');
        assert.false(this.resource.servicable);
        assert.strictEqual(this.rateCalls.length, 1, 'switching off does not query rates');
        assert.dom('.ember-power-select-trigger').doesNotExist();
    });

    test('the toggle is disabled without an order config or route, and without write access', async function (assert) {
        this.set('resource', this.makeOrder({ order_config: null }));
        await render(hbs`<Order::Form::ServiceRate @resource={{this.resource}} />`);
        assert.dom('[role="checkbox"]').hasAttribute('data-disabled');

        this.set('resource', this.makeOrder({ payloadCoordinates: [], payload: { payloadCoordinates: [] }, servicable: true }));
        await render(hbs`<Order::Form::ServiceRate @resource={{this.resource}} />`);
        assert.dom('[role="checkbox"]').hasAttribute('data-disabled');
        assert.dom().includesText('Input order route to view service quotes.');

        this.allowWrite = false;
        this.set('resource', this.makeOrder({ servicable: true }));
        await render(hbs`<Order::Form::ServiceRate @resource={{this.resource}} />`);
        assert.dom('[role="checkbox"]').hasAttribute('data-disabled');
        assert.dom('.ember-power-select-trigger').hasAttribute('aria-disabled', 'true');

        this.allowWrite = true;
        this.set('resource', this.makeOrder({ servicable: true, facilitator: { isIntegratedVendor: true } }));
        await render(hbs`<Order::Form::ServiceRate @resource={{this.resource}} />`);
        assert.dom('.ember-power-select-trigger').doesNotExist('integrated vendors pick no rate');
    });

    test('a refresh request debounces a quote lookup for the matching order only', async function (assert) {
        this.quotes = [{ uuid: 'fresh-quote', public_id: 'sq_f', service_rate_name: 'Local Route Rate', request_id: 'req_f', currency: 'USD', amount: 100, items: [] }];
        this.set('resource', this.makeOrder({ servicable: true, service_quote_uuid: 'stale-quote' }));

        await render(hbs`<Order::Form::ServiceRate @resource={{this.resource}} />`);
        assert.strictEqual(this.rateCalls.length, 0, 'rendering alone does not query rates');

        this.orderCreation.requestServiceQuoteRefresh('entity.added', { id: 'other-order' });
        await settled();
        assert.strictEqual(this.rateCalls.length, 0, 'another order is ignored');

        this.orderCreation.requestServiceQuoteRefresh('entity.added', this.resource);
        await settled();
        assert.strictEqual(this.rateCalls.length, 1, 'a refresh loads the rates of a servicable order');
        assert.strictEqual(this.quoteCalls.length, 0, 'ignored until a rate is selected');

        await selectRate(0);
        assert.strictEqual(this.quoteCalls.length, 1);
        this.resource.service_quote_uuid = 'stale-quote';

        this.pendingQuotes = new Promise((resolve) => (this.resolveQuotes = resolve));
        this.orderCreation.requestServiceQuoteRefresh('entity.measurements.changed', this.resource);
        this.orderCreation.requestServiceQuoteRefresh('entity.measurements.changed');
        await waitUntil(() => this.quoteCalls.length === 2, { timeout: 2000 });
        assert.strictEqual(this.quoteCalls.length, 2, 'two requests debounce into one lookup');
        assert.dom().includesText('Updating service quotes...', 'existing quotes stay while auto-refreshing');
        assert.dom('.radio-group-item').exists({ count: 1 });

        this.resolveQuotes([{ uuid: 'fresh-quote-2', public_id: 'sq_f2', service_rate_name: 'Local Route Rate', request_id: 'req_f2', currency: 'USD', amount: 100, items: [] }]);
        await settled();
        assert.dom().doesNotIncludeText('Updating service quotes...');
        assert.dom().includesText('sq_f2');
        assert.strictEqual(this.resource.service_quote_uuid, null, 'the stale selection is cleared');
    });

    test('a refresh request loads the rates for a servicable order that has none yet', async function (assert) {
        this.rates = [];
        this.set('resource', this.makeOrder({ servicable: true }));

        await render(hbs`<Order::Form::ServiceRate @resource={{this.resource}} />`);
        this.orderCreation.requestServiceQuoteRefresh('entity.added', this.resource);
        await settled();
        assert.strictEqual(this.rateCalls.length, 1);

        this.orderCreation.requestServiceQuoteRefresh('entity.added', this.resource);
        await settled();
        assert.strictEqual(this.rateCalls.length, 2, 'rates are queried again while none are loaded');

        this.set('resource', this.makeOrder({ id: 'order_2', servicable: true, payloadCoordinates: [], payload: { payloadCoordinates: [] } }));
        await render(hbs`<Order::Form::ServiceRate @resource={{this.resource}} />`);
        this.rateCalls.length = 0;
        this.orderCreation.requestServiceQuoteRefresh('entity.added', this.resource);
        await settled();
        assert.strictEqual(this.quoteCalls.length, 0, 'no route means no quote refresh');
    });

    test('a refresh that loses its route before the debounce elapses does nothing', async function (assert) {
        this.set('resource', this.makeOrder({ servicable: true }));
        await render(hbs`<Order::Form::ServiceRate @resource={{this.resource}} />`);
        this.orderCreation.requestServiceQuoteRefresh('entity.added', this.resource);
        await settled();
        await selectRate(0);
        assert.strictEqual(this.quoteCalls.length, 1);

        this.orderCreation.requestServiceQuoteRefresh('entity.added', this.resource);
        this.resource.payloadCoordinates = [];
        await settled();
        assert.strictEqual(this.quoteCalls.length, 1, 'the debounced lookup re-checks the route');
        assert.dom().doesNotIncludeText('Updating service quotes...');
    });

    test('a locked contract quote replaces the selector', async function (assert) {
        this.set('resource', this.makeOrder({ servicable: true }));
        this.orderCreation.setServiceQuoteOverride('contract', { mode: 'locked', title: 'Contract pricing', description: 'Rates fixed by contract', isLoading: true });

        await render(hbs`<Order::Form::ServiceRate @resource={{this.resource}} />`);
        assert.dom().includesText('Contract pricing');
        assert.dom().includesText('Rates fixed by contract');
        assert.dom().includesText('Locked');
        assert.dom().includesText('Generating contract quote...');
        assert.dom('[role="checkbox"]').doesNotExist();

        this.orderCreation.setServiceQuoteOverride('contract', {
            mode: 'locked',
            title: 'Contract pricing',
            quote: { amount: 2500, items: [{ details: 'Flat contract fee', amount: 2500 }] },
        });
        await render(hbs`<Order::Form::ServiceRate @resource={{this.resource}} />`);
        assert.dom().includesText('Flat contract fee');
        assert.dom().includesText('$25.00');
        assert.dom().doesNotIncludeText('Rates fixed by contract');

        this.orderCreation.setServiceQuoteOverride('contract', { mode: 'locked', title: 'EUR contract', currency: 'EUR', quote: { amount: 500 } });
        await render(hbs`<Order::Form::ServiceRate @resource={{this.resource}} />`);
        assert.ok(/€\s?5[.,]00/.test(this.element.textContent), 'EUR from the override');

        this.orderCreation.setServiceQuoteOverride('contract', { mode: 'locked', title: 'Bare', quote: { amount: 300, currency: 'GBP' } });
        await render(hbs`<Order::Form::ServiceRate @resource={{this.resource}} />`);
        assert.ok(/£\s?3[.,]00/.test(this.element.textContent), 'GBP from the quote');

        this.orderCreation.requestServiceQuoteRefresh('entity.added', this.resource);
        await settled();
        assert.strictEqual(this.quoteCalls.length, 0, 'a locked quote ignores refresh requests');

        this.orderCreation.clearServiceQuoteOverride('contract');
        await render(hbs`<Order::Form::ServiceRate @resource={{this.resource}} />`);
        assert.dom('[role="checkbox"]').exists();
    });
});
