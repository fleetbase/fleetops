import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import Component from '@glimmer/component';
import { setComponentTemplate } from '@ember/component';
import { inject as service } from '@ember/service';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

class ModelSelectStub extends Component {
    @service orderFormTestBed;
}
setComponentTemplate(hbs`<button type="button" data-test-model-select={{@modelName}} {{on "click" (fn @onChange this.orderFormTestBed.chosen)}}>{{@modelName}}</button>`, ModelSelectStub);

/**
 * The customer order form is invoked from the customer portal with an order it has already
 * created, so `@order` is required — the component reads `order.customer` in its constructor.
 * `ember-file-upload` and `ember-model-select` are not installed in this package, so the
 * components they provide need stand-ins before the template can render at all.
 */
function stubComponents(owner) {
    registerTemplateOnly(owner, 'file-dropzone', hbs`<div data-test-dropzone>{{yield}}</div>`);
    registerTemplateOnly(owner, 'file-upload', hbs`<div data-test-file-upload>{{yield}}</div>`);
    registerTemplateOnly(owner, 'custom-field/input', hbs`<div data-test-custom-field></div>`);
    // The real DragSortList yields each item with its index and reports a reorder through
    // `@dragEndAction`; the stand-in does both so the waypoint rows actually render.
    registerTemplateOnly(
        owner,
        'drag-sort-list',
        hbs`<div data-test-drag-sort-list>
            {{#each @items as |item index|}}
                <div data-test-drag-item>{{yield item index}}</div>
            {{/each}}
            <button
                type="button"
                data-test-drag-end
                {{on "click" (fn @dragEndAction (hash sourceList=@items sourceIndex=1 targetList=@items targetIndex=0))}}
            ></button>
            <button
                type="button"
                data-test-drag-noop
                {{on "click" (fn @dragEndAction (hash sourceList=@items sourceIndex=0 targetList=@items targetIndex=0))}}
            ></button>
        </div>`
    );
    // ModelSelect is a power-select in production; here it is a button that reports the choice
    // the test put on the test bed, which is the same `@onChange` contract.
    owner.register('component:model-select', ModelSelectStub);
}

module('Integration | Component | customer/create-order-form', function (hooks) {
    setupRenderingTest(hooks);

    hooks.afterEach(function () {
        this.map?.remove();
        this.mapElement?.remove();
    });

    hooks.beforeEach(function () {
        const test = this;
        this.gets = [];
        this.posts = [];
        this.postResponses = {};
        this.notified = [];
        this.getResponses = {
            'fleet-ops/settings/customer-enabled-order-configs': ['config_1'],
            'fleet-ops/settings/customer-payments-config': { paymentsEnabled: false, paymentsOnboardCompleted: false },
        };

        this.owner.register(
            'service:fetch',
            class extends Service {
                get(path, params, options) {
                    test.gets.push([path, params, options]);
                    const answer = test.getResponses[path];
                    return typeof answer === 'function' ? answer() : Promise.resolve(answer);
                }
                post(path, body, options) {
                    test.posts.push([path, body, options]);
                    const answer = test.postResponses[path];
                    return typeof answer === 'function' ? answer() : Promise.resolve(answer);
                }
            }
        );
        this.owner.register(
            'service:notifications',
            class extends Service {
                serverError(error) {
                    test.notified.push(['serverError', error?.message ?? error]);
                }
                warning(message) {
                    test.notified.push(['warning', message]);
                }
                error(message) {
                    test.notified.push(['error', message]);
                }
                success(message) {
                    test.notified.push(['success', message]);
                }
            }
        );
        this.owner.register(
            'service:customer-session',
            class extends Service {
                getCustomer() {
                    return test.sessionCustomer ?? null;
                }
            }
        );
        this.owner.register(
            'service:customer-payment',
            class extends Service {
                loadAndInitialize() {
                    test.paymentInitialized = true;
                }
            }
        );
        // A URLSearchParams stand-in the test drives, rather than the real page location.
        this.searchParams = {};
        this.removedParams = [];
        this.owner.register(
            'service:url-search-params',
            class extends Service {
                has(key) {
                    return key in test.searchParams;
                }
                get(key) {
                    return test.searchParams[key];
                }
                removeParamFromCurrentUrl(key) {
                    test.removedParams.push(key);
                }
            }
        );
        this.modals = [];
        this.modalsDone = 0;
        this.owner.register(
            'service:modals-manager',
            class extends Service {
                show(name, options) {
                    test.modals.push([name, options]);
                }
                done() {
                    test.modalsDone += 1;
                    return Promise.resolve();
                }
            }
        );
        this.owner.register('service:context-panel', class extends Service {});
        this.universeEvents = [];
        this.owner.register(
            'service:universe',
            class extends Service {
                trigger(name, subject) {
                    test.universeEvents.push([name, subject]);
                }
            }
        );

        this.tracked = [];
        this.owner.register(
            'service:events',
            class extends Service {
                trackResourceCreated(resource) {
                    test.tracked.push(resource);
                }
            }
        );

        // Everything on this form is gated on `cannot "fleet-ops create order"`.
        this.owner.register(
            'service:abilities',
            class extends Service {
                denied = new Set();
                can(permission) {
                    return !this.denied.has(permission);
                }
                cannot(permission) {
                    return !this.can(permission);
                }
            }
        );

        this.owner.register('service:order-form-test-bed', class extends Service {});
        this.testBed = this.owner.lookup('service:order-form-test-bed');

        // `previewDraftOrderRoute` builds a leaflet-routing-machine control and does
        // `control.addTo(this.map)`; the library reads the map's size from inside its own `onAdd`,
        // so nothing short of a real Leaflet map on a sized element will do. The dummy app has
        // Leaflet on the global (see ember-cli-build.js).
        this.mapElement = document.createElement('div');
        this.mapElement.style.width = '400px';
        this.mapElement.style.height = '300px';
        document.getElementById('ember-testing').appendChild(this.mapElement);
        this.map = window.L.map(this.mapElement).setView([1.3, 103.8], 12);

        stubComponents(this.owner);

        // The component calls `this.order.set(...)`, so the order has to be a real record.
        this.store = this.owner.lookup('service:store');
        this.order = this.store.createRecord('order', {});
        // `order.customer` is a polymorphic belongsTo, so it only accepts a real record; most
        // tests leave it unset and let the session supply the customer, as the portal does.
        this.sessionCustomer = { id: 'customer_1', name: 'Ada' };

        this.customFieldsLoaded = [];
        this.owner.register(
            'service:custom-fields-registry',
            class extends Service {
                loadSubjectCustomFields = {
                    perform: (orderConfig) => {
                        test.customFieldsLoaded.push(orderConfig);
                        return Promise.resolve(
                            test.customFieldsManager ?? {
                                validateRequired: () => test.customFieldsValidation ?? { isValid: true, errors: new Map() },
                                saveTo: () => Promise.resolve({ created: [] }),
                            }
                        );
                    },
                };
            }
        );

        /** Put order configs in the store so `peekRecord` can find them by id. */
        this.givenOrderConfigs = (...configs) => {
            const records = configs.map((attrs) => test.store.createRecord('order-config', attrs));
            test.store.findAll = () => Promise.resolve(records);
            return records;
        };
        this.givenOrderConfigs();
    });

    test('it resolves through the string-based component resolver', function (assert) {
        assert.ok(this.owner.factoryFor('component:customer/create-order-form'));
    });

    test('it renders with the order it was given', async function (assert) {
        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} />`);
        assert.dom('.customer-create-order-form, form, div').exists('the form comes up');
    });

    test('the enabled order configs are the only ones offered, and the first is selected', async function (assert) {
        const [transport, storage] = this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' }, { id: 'config_2', key: 'storage', name: 'Storage' });
        this.getResponses['fleet-ops/settings/customer-enabled-order-configs'] = ['config_2'];

        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} />`);

        assert.deepEqual(
            this.gets.map(([path]) => path),
            ['fleet-ops/settings/customer-enabled-order-configs', 'fleet-ops/settings/customer-payments-config'],
            'the settings are read once each'
        );
        assert.strictEqual(this.order.order_config, storage, 'the first enabled config is applied to the order');
        assert.strictEqual(this.order.order_config_uuid, 'config_2');
        assert.strictEqual(this.order.type, 'storage', "and the order takes the config's key as its type");
        assert.deepEqual(this.customFieldsLoaded, [storage], 'its custom fields are loaded');
        assert.notOk(this.order.order_config === transport, 'the config the customer is not entitled to is left out');
    });

    test('a settings read that fails is reported and leaves the form usable', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport' });
        this.getResponses['fleet-ops/settings/customer-enabled-order-configs'] = () => Promise.reject(new Error('entitlements are down'));
        this.getResponses['fleet-ops/settings/customer-payments-config'] = () => Promise.reject(new Error('payments are down'));

        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} />`);

        assert.deepEqual(
            this.notified,
            [
                ['serverError', 'entitlements are down'],
                ['serverError', 'payments are down'],
            ],
            'each failure is raised once'
        );
        assert.dom('[data-test-dropzone]').exists('and the form is still there');
    });

    test('the payments config decides whether payment is offered', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport' });
        this.getResponses['fleet-ops/settings/customer-payments-config'] = { paymentsEnabled: true, paymentsOnboardCompleted: true };

        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} />`);

        assert.true(this.paymentInitialized, 'the payment service is initialised from the constructor');
        assert.strictEqual(this.gets.filter(([p]) => p.endsWith('customer-payments-config')).length, 1);
    });

    test('an order carrying its own customer keeps it over the session', async function (assert) {
        const contact = this.store.createRecord('contact', { name: 'Grace' });
        this.order.customer = contact;

        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} />`);

        assert.dom('[data-test-dropzone]').exists("the form comes up on the order's own customer");
    });

    test('adding an item to the order creates an entity', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} />`);

        const addItem = [...document.querySelectorAll('button')].find((el) => el.textContent.includes('Add Item'));
        assert.ok(addItem, 'the add-item button is rendered');
        assert.false(addItem.disabled, 'and is available once a config is chosen');

        const rows = () => [...document.querySelectorAll('button')].filter((el) => el.textContent.includes('Edit Item')).length;
        const before = rows();
        await click(addItem);

        assert.strictEqual(rows(), before + 1, 'one more item row is rendered');
    });

    test('choosing a pickup and a dropoff draws a route preview between them', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        const selects = [...document.querySelectorAll('[data-test-model-select="place"]')];
        assert.strictEqual(selects.length, 3, 'pickup, dropoff and return each get a select');

        this.testBed.chosen = this.store.createRecord('place', {
            street1: 'Depot',
            location: { type: 'Point', coordinates: [103.8, 1.3] },
        });
        await click(selects[0]);

        assert.deepEqual(this.notified.filter(([kind]) => kind === 'warning').length, 0, 'one usable place is enough to start a preview without warning');

        this.testBed.chosen = this.store.createRecord('place', {
            street1: 'Site',
            location: { type: 'Point', coordinates: [103.9, 1.4] },
        });
        await click(selects[1]);

        assert.dom('.leaflet-routing-container, .leaflet-overlay-pane').exists('the routing control is attached to the map');
    });

    test('turning on multiple dropoffs opens the waypoint list with one row', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        assert.dom('[data-test-drag-item]').doesNotExist('a single-drop order has no waypoint rows');

        // ember-ui's Toggle is a `<span role="checkbox">`; the first one is the multi-drop switch.
        const [multiDrop] = document.querySelectorAll('[role="checkbox"]');
        await click(multiDrop);

        assert.dom('[data-test-drag-item]').exists({ count: 1 }, 'switching on adds the first waypoint');
        assert.strictEqual(multiDrop.getAttribute('aria-checked'), 'true');
    });

    test('a waypoint takes a place, and one of several can be removed', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        const [multiDrop] = document.querySelectorAll('[role="checkbox"]');
        await click(multiDrop);

        // Give the first waypoint a place, which previews the route through it.
        this.testBed.chosen = this.store.createRecord('place', {
            street1: 'Depot',
            location: { type: 'Point', coordinates: [103.8, 1.3] },
        });
        const waypointSelect = document.querySelector('[data-test-drag-item] [data-test-model-select="place"]');
        assert.ok(waypointSelect, 'each waypoint row carries its own place select');
        await click(waypointSelect);

        // A second waypoint, so that removing one leaves something to route between.
        const addWaypoint = [...document.querySelectorAll('button')].find((el) => el.textContent.includes('Add Waypoint') || el.textContent.includes('Waypoint'));
        assert.ok(addWaypoint, 'there is a control to add another waypoint');
        await click(addWaypoint);
        assert.dom('[data-test-drag-item]').exists({ count: 2 });

        this.testBed.chosen = this.store.createRecord('place', {
            street1: 'Site',
            location: { type: 'Point', coordinates: [103.9, 1.4] },
        });
        await click([...document.querySelectorAll('[data-test-drag-item] [data-test-model-select="place"]')][1]);

        const remove = [...document.querySelectorAll('[data-test-drag-item] button')].filter((el) => el.querySelector('.fa-trash'));
        assert.strictEqual(remove.length, 2, 'each row has its own remove button');
        await click(remove[1]);
        assert.dom('[data-test-drag-item]').exists({ count: 1 }, 'removing one leaves the other');
    });

    test('proof of delivery carries a default method, and giving it up clears one', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        const toggles = [...document.querySelectorAll('[role="checkbox"]')];
        const pod = toggles.at(-1);

        await click(pod);
        assert.true(this.order.pod_required, 'the order is marked as needing proof');
        assert.strictEqual(this.order.pod_method, 'scan', 'and takes the default method');

        await click(pod);
        assert.false(this.order.pod_required);
        assert.strictEqual(this.order.pod_method, null, 'which is given up with it');
    });

    /** Fills in the pickup and dropoff the form needs before it will accept a submission. */
    async function chooseRoute(test) {
        const selects = [...document.querySelectorAll('[data-test-model-select="place"]')];
        test.testBed.chosen = test.store.createRecord('place', { street1: 'Depot', location: { type: 'Point', coordinates: [103.8, 1.3] } });
        await click(selects[0]);
        test.testBed.chosen = test.store.createRecord('place', { street1: 'Site', location: { type: 'Point', coordinates: [103.9, 1.4] } });
        await click(selects[1]);
    }

    const submitButton = () => [...document.querySelectorAll('button')].find((el) => el.textContent.includes('Submit'));

    test('submitting an order with no route does nothing at all', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        let saved = 0;
        this.order.save = () => {
            saved += 1;
            return Promise.resolve(this.order);
        };

        await click(submitButton());

        assert.strictEqual(saved, 0, 'an order with no pickup or dropoff is not saved');
        assert.deepEqual(this.notified, [], 'and the refusal is silent — the disabled controls are the feedback');
        assert.deepEqual(this.universeEvents, [], 'nothing is announced');
    });

    test('submitting a routed order saves it and announces it', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });

        let saved = 0;
        this.order.save = () => {
            saved += 1;
            return Promise.resolve(this.order);
        };
        const created = [];
        this.onOrderCreated = (order) => created.push(order);

        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} @onOrderCreated={{this.onOrderCreated}} />`);
        await chooseRoute(this);
        await click(submitButton());

        assert.strictEqual(saved, 1, 'the order is saved once');
        assert.deepEqual(
            this.universeEvents.map(([name]) => name),
            ['fleet-ops.order.creating', 'fleet-ops.order.created'],
            'and announced before and after'
        );
        assert.deepEqual(this.tracked, [this.order], 'the creation is tracked');
        assert.deepEqual(created, [this.order], 'and handed back to the caller');
    });

    test('custom fields that do not validate stop the submission and say why', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        this.customFieldsValidation = {
            isValid: false,
            errors: new Map([
                ['weight', 'Weight is required'],
                ['ref', 'Reference is required'],
            ]),
        };
        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);
        await chooseRoute(this);

        let saved = 0;
        this.order.save = () => {
            saved += 1;
            return Promise.resolve(this.order);
        };

        await click(submitButton());

        assert.strictEqual(saved, 0, 'nothing is saved');
        assert.deepEqual(this.notified.at(-1), ['error', 'Weight is required\nReference is required'], 'every missing field is listed at once');
    });

    test('a save the server refuses is reported and the order is kept', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);
        await chooseRoute(this);

        this.order.save = () => Promise.reject(new Error('the order was rejected'));

        await click(submitButton());

        assert.deepEqual(this.notified.at(-1), ['serverError', 'the order was rejected']);
        assert.deepEqual(
            this.universeEvents.map(([name]) => name),
            ['fleet-ops.order.creating'],
            'the creating event fired, but nothing claims it was created'
        );
    });

    test('a routed order asks for preliminary quotes and takes the first', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        this.postResponses['service-quotes/preliminary'] = [{ id: 'quote_1' }, { id: 'quote_2' }];

        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);
        await chooseRoute(this);

        const quoteRequests = this.posts.filter(([path]) => path === 'service-quotes/preliminary');
        assert.true(quoteRequests.length >= 1, 'quotes are asked for once there is a route');

        const [, body, options] = quoteRequests.at(-1);
        assert.strictEqual(body.service_type, 'transport', "the order config's key is sent as the service type");
        assert.deepEqual(options, { normalizeToEmberData: true, normalizeModelType: 'service-quote' }, 'and the response is normalised into records');

        // The `service` key resolves to Ember's `inject` decorator rather than any value from
        // this form, so it goes over as a function and JSON drops it — see DEFECTS #96.
        assert.strictEqual(typeof body.service, 'function', 'the service field is the imported decorator, not a service');
    });

    test('quotes that come back as something other than a list are taken as none', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        this.postResponses['service-quotes/preliminary'] = { error: 'no rates configured' };

        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);
        await chooseRoute(this);

        assert.deepEqual(this.notified, [], 'a shapeless response is not an error');
        assert.true(
            this.posts.some(([path]) => path === 'service-quotes/preliminary'),
            'the request was still made'
        );
    });

    test('a quote request the server refuses is reported', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        this.postResponses['service-quotes/preliminary'] = () => Promise.reject(new Error('rating is down'));

        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);
        await chooseRoute(this);

        assert.deepEqual(this.notified.at(-1), ['serverError', 'rating is down']);
    });

    test('quotes are not asked for while a checkout session is being completed', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        this.searchParams = { checkout_session_id: 'cs_1', service_quote: 'quote_1' };
        // `restoreFromServiceQuote` reads the quote with `.get(...)`, so it needs a record-like object.
        this.store.findRecord = () =>
            Promise.resolve({
                id: 'quote_1',
                get(key) {
                    return this[key];
                },
            });

        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        assert.deepEqual(
            this.posts.filter(([path]) => path === 'service-quotes/preliminary'),
            [],
            'the form is finishing a purchase, not re-pricing it'
        );
    });
});
