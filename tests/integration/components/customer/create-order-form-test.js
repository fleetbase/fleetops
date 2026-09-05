import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, render, settled } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import Component from '@glimmer/component';
import { setComponentTemplate } from '@ember/component';
import { inject as service } from '@ember/service';
import { helper } from '@ember/component/helper';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

/** Every `file-queue` the template builds, so a test can drop a file onto one. */
const queueHandles = [];
import { A } from '@ember/array';
import { get as emberGet } from '@ember/object';

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
    // `file-queue` comes from ember-file-upload, which is not installed here. The stand-in hands
    // the queue back and parks `onFileAdded` where a test can invoke it, as a dropped file would.
    owner.register(
        'helper:file-queue',
        helper((positional, named) => {
            queueHandles.push(named);
            return { name: named.name, files: [], progress: 0, onFileAdded: named.onFileAdded, remove: () => {} };
        })
    );
    registerTemplateOnly(owner, 'file-upload', hbs`<div data-test-file-upload>{{yield}}</div>`);
    registerTemplateOnly(owner, 'custom-field/input', hbs`<div data-test-custom-field></div>`);
    // The real DragSortList yields each item with its index and reports a reorder through
    // `@dragEndAction`; the stand-in does both so the waypoint rows actually render.
    registerTemplateOnly(
        owner,
        'drag-sort-list',
        hbs`<div data-test-drag-sort-list>
            {{#each @items as |item index|}}
                <div data-test-drag-item data-test-waypoint={{item.place.street1}}>{{yield item index}}</div>
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
                uploadFile = {
                    perform: (file, meta, onSuccess, onError) => {
                        test.uploads.push({ file, meta, onSuccess, onError });
                        return Promise.resolve();
                    },
                };
                post(path, body, options) {
                    test.posts.push([path, body, options]);
                    const answer = test.postResponses[path];
                    return typeof answer === 'function' ? answer() : Promise.resolve(answer);
                }
                // The real one is `store.push(store.normalize(type, attributes))`, which is what
                // makes a restored record a *loaded* one rather than a new one — the whole
                // difference the saved-item paths turn on. Keep it exact.
                jsonToModel(attributes = {}, modelType) {
                    return test.store.push(test.store.normalize(modelType, attributes));
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
        this.checkouts = [];
        this.owner.register(
            'service:customer-payment',
            class extends Service {
                get loaded() {
                    return test.paymentLoaded === true;
                }
                loadAndInitialize() {
                    test.paymentInitialized = true;
                }
                initEmbeddedCheckout(options) {
                    test.checkouts.push(options);
                    return Promise.resolve({
                        mount: (el) => test.checkouts.push(['mount', el]),
                        destroy: () => test.checkouts.push(['destroy']),
                    });
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
                options = {};
                setOption(key, value) {
                    this.options[key] = value;
                }
                getOption(key) {
                    return this.options[key];
                }
                // Mirrors ember-ui's ModalsManager#invoke exactly: the second positional is the
                // modal *id*, not a parameter. That signature is the whole of DEFECTS #98, so the
                // stand-in has to keep it or the test would only be asserting its own shortcut.
                invoke(fn, modalId = null, ...params) {
                    const entry = modalId ? test.modals.find(([, options]) => options.id === modalId) : test.modals.at(-1);
                    if (!entry) {
                        return null;
                    }
                    const callable = entry[1][fn];
                    return typeof callable === 'function' ? callable(...params) : null;
                }
                startLoading() {
                    test.modalLoading = true;
                }
                stopLoading() {
                    test.modalLoading = false;
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

        queueHandles.length = 0;
        stubComponents(this.owner);

        // The component calls `this.order.set(...)`, so the order has to be a real record.
        this.store = this.owner.lookup('service:store');
        this.order = this.store.createRecord('order', {});
        // `order.customer` is a polymorphic belongsTo, so it only accepts a real record; most
        // tests leave it unset and let the session supply the customer, as the portal does.
        this.sessionCustomer = { id: 'customer_1', name: 'Ada' };

        this.customFieldsLoaded = [];
        this.uploads = [];
        this.owner.register(
            'service:current-user',
            class extends Service {
                companyId = 'company_1';
                options = {};
                getOption(key) {
                    return this.options[key];
                }
                setOption(key, value) {
                    this.options[key] = value;
                }
            }
        );

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
        // The component reads `serviceQuotes.firstObject`, so the response has to be an Ember array.
        this.postResponses['service-quotes/preliminary'] = A([{ id: 'quote_1' }, { id: 'quote_2' }]);

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

    test('returning from a checkout puts up the completing-purchase dialog', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        this.searchParams = { checkout_session_id: 'cs_1', service_quote: 'quote_1' };
        this.store.findRecord = () =>
            Promise.resolve({
                id: 'quote_1',
                get(key) {
                    return this[key];
                },
            });

        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        const dialog = this.modals.find(([name]) => name === 'modals/confirm-service-quote-purchase');
        assert.ok(dialog, 'the customer is told the purchase is being finalised');
        assert.false(dialog[1].backdropClose, 'and cannot dismiss it by clicking away');
    });

    test('the quotes that come back are offered, with the first already chosen', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        this.postResponses['service-quotes/preliminary'] = A([
            { id: 'quote_1', amount: 1200 },
            { id: 'quote_2', amount: 2400 },
        ]);

        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);
        await chooseRoute(this);

        assert.dom('.radio-group-item').exists({ count: 2 }, 'both quotes are offered');
        assert.dom('.radio-group-item.is-checked').exists({ count: 1 }, 'and exactly one is chosen');
        assert.strictEqual(
            [...document.querySelectorAll('.radio-group-item')].findIndex((el) => el.classList.contains('is-checked')),
            0,
            'the first quote is the one taken'
        );
    });

    test('a dropped file is queued and uploaded, and lands on the order', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        const queue = queueHandles.at(-1);
        assert.ok(queue, 'the template builds a file queue');
        assert.true(queue.accept.includes('application/pdf'), 'restricted to the types the form accepts');

        // A file the dropzone has accepted but not yet sent.
        await queue.onFileAdded({ state: 'queued', queue: { remove: () => {} } });

        assert.strictEqual(this.uploads.length, 1, 'the file is uploaded straight away');
        assert.deepEqual(this.uploads[0].meta, { path: 'uploads/fleet-ops/order-files', type: 'order_file' }, 'under the order-files path');

        const uploaded = this.store.createRecord('file', { original_filename: 'manifest.pdf' });
        this.uploads[0].onSuccess(uploaded);
        assert.deepEqual([...this.order.files], [uploaded], 'and once it lands it belongs to the order');
    });

    test('a file already sent is not queued twice, and a failed upload leaves the queue clean', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        const queue = queueHandles.at(-1);
        await queue.onFileAdded({ state: 'uploaded', queue: { remove: () => {} } });
        assert.strictEqual(this.uploads.length, 0, 'a file already sent is ignored — the dropzone calls this twice');

        const removed = [];
        await queue.onFileAdded({ state: 'failed', queue: { remove: (file) => removed.push(file) } });
        assert.strictEqual(this.uploads.length, 1, 'but a failed one is retried');

        this.uploads[0].onError();
        assert.strictEqual(removed.length, 1, 'and a second failure takes it out of the queue');
        assert.deepEqual([...this.order.files], [], 'with nothing attached to the order');
    });

    test('switching the order type takes the form to that config', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' }, { id: 'config_2', key: 'storage', name: 'Storage' });
        // The form only offers the configs the settings call says are enabled for this customer.
        this.getResponses['fleet-ops/settings/customer-enabled-order-configs'] = ['config_1', 'config_2'];
        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        assert.strictEqual(this.order.order_config_uuid, 'config_1', 'the first config is taken to start with');

        const typeSelect = document.querySelector('select.form-select');
        await fillIn(typeSelect, 'config_2');

        assert.strictEqual(this.order.order_config_uuid, 'config_2', 'choosing another moves the order onto it');
        assert.deepEqual(
            this.customFieldsLoaded.map((config) => config.id),
            ['config_1', 'config_2'],
            'and its custom fields are loaded in turn'
        );
    });

    test('the overlay cancel button hands back to whoever opened the form', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        this.cancelled = 0;
        this.onCancel = () => (this.cancelled += 1);

        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} @onCancel={{this.onCancel}} />`);

        await click('.next-content-overlay-panel-cancel-button');
        assert.strictEqual(this.cancelled, 1, 'the caller is told the customer backed out');
    });

    test('an address that has been chosen can be opened for editing', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        const pickup = this.store.createRecord('place', { street1: 'Depot', location: { type: 'Point', coordinates: [103.8, 1.3] } });
        this.testBed.chosen = pickup;
        await click([...document.querySelectorAll('[data-test-model-select="place"]')][0]);

        const editLinks = [...document.querySelectorAll('a')].filter((el) => el.querySelector('.fa-pen-to-square, .fa-edit'));
        assert.ok(editLinks.length, 'a chosen address offers an edit control');
        await click(editLinks[0]);

        const [name, options] = this.modals.at(-1);
        assert.strictEqual(name, 'modals/place-form');
        assert.strictEqual(options.title, 'Edit Place', 'opened for editing, not for a new one');
        assert.strictEqual(options.place, pickup, 'on the address that was clicked');
    });

    test('dragging a waypoint to a new position reorders the stops, and dropping it back does nothing', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        const [multiDrop] = document.querySelectorAll('[role="checkbox"]');
        await click(multiDrop);

        this.testBed.chosen = this.store.createRecord('place', { street1: 'Depot', location: { type: 'Point', coordinates: [103.8, 1.3] } });
        await click(document.querySelector('[data-test-drag-item] [data-test-model-select="place"]'));

        await click([...document.querySelectorAll('button')].find((el) => el.textContent.includes('Waypoint')));
        this.testBed.chosen = this.store.createRecord('place', { street1: 'Site', location: { type: 'Point', coordinates: [103.9, 1.4] } });
        await click([...document.querySelectorAll('[data-test-drag-item] [data-test-model-select="place"]')][1]);

        const order = () => [...document.querySelectorAll('[data-test-drag-item]')].map((el) => el.getAttribute('data-test-waypoint'));
        assert.deepEqual(order(), ['Depot', 'Site'], 'two stops, in the order they were added');

        // The stand-in reports source 1 -> target 0, which is what DragSortList hands back.
        await click('[data-test-drag-end]');
        assert.deepEqual(order(), ['Site', 'Depot'], 'the stop that was dragged is now first');

        // Dropping a stop back where it started is reported too, and has to be ignored.
        await click('[data-test-drag-noop]');
        assert.deepEqual(order(), ['Site', 'Depot'], 'putting it back where it was changes nothing');
    });

    test('the map is nudged off the panel once it has settled on the route', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        const panned = [];
        this.map.panBy = (offset) => panned.push(offset);

        // One address is a single point, which the form flies to rather than fitting bounds.
        this.testBed.chosen = this.store.createRecord('place', { street1: 'Depot', location: { type: 'Point', coordinates: [103.8, 1.3] } });
        await click([...document.querySelectorAll('[data-test-model-select="place"]')][0]);
        this.map.fire('moveend');
        assert.strictEqual(panned.length, 1, 'the single-point fly-to nudges the map once it lands');

        // A second makes it two, which is fitted instead — a different arm, same nudge.
        this.testBed.chosen = this.store.createRecord('place', { street1: 'Site', location: { type: 'Point', coordinates: [103.9, 1.4] } });
        await click([...document.querySelectorAll('[data-test-model-select="place"]')][1]);
        this.map.fire('moveend');
        assert.strictEqual(panned.length, 2, 'and so does the fitted two-point route');
    });

    test('a file on the order can be taken off again', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        const queue = queueHandles.at(-1);
        await queue.onFileAdded({ state: 'queued', queue: { remove: () => {} } });
        const uploaded = this.store.createRecord('file', { original_filename: 'manifest.pdf' });
        let destroyed = false;
        uploaded.destroyRecord = () => {
            destroyed = true;
            return Promise.resolve(uploaded);
        };
        this.uploads[0].onSuccess(uploaded);
        await settled();

        const dropdownTrigger = document.querySelector('.ember-basic-dropdown-trigger');
        assert.ok(dropdownTrigger, 'the attached file carries its own menu');
        await click(dropdownTrigger);

        const remove = [...document.querySelectorAll('[role="menuitem"]')].find((el) => el.querySelector('.fa-trash'));
        assert.ok(remove, 'with a delete on it');
        await click(remove);
        assert.true(destroyed, 'and the file is deleted');
    });

    test('the payload sent for quoting is serialised model by model', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        await click([...document.querySelectorAll('button')].find((el) => el.textContent.includes('Add Item')));
        await chooseRoute(this);

        const [, body] = this.posts.find(([path]) => path === 'service-quotes/preliminary');
        assert.ok(body.payload, 'the payload goes with the quote request');
        assert.notOk(body.payload.pickup instanceof Object && typeof body.payload.pickup.save === 'function', 'the pickup is sent as plain attributes, not as a record');
        assert.strictEqual(body.payload.pickup.street1, 'Depot', 'with the attributes the record carried');
        assert.true(Array.isArray(body.payload.entities), 'and the items are serialised one by one');
        assert.strictEqual(body.payload.entities.length, 1);
    });

    /**
     * Put the form in the state it is in when the customer comes back from Stripe: the URL carries
     * the checkout session and the quote, and the order the customer had built is sitting in the
     * user's options under that quote's id. `restoreFromServiceQuote` reads it back from there.
     */
    function givenCheckoutReturn(test, { state, quotePayload = {}, checkoutResponse } = {}) {
        test.searchParams = { checkout_session_id: 'cs_1', service_quote: 'quote_1' };
        test.currentUser = test.owner.lookup('service:current-user');
        // Restored waypoints are built with `{ place, customer }`, and `waypoint.customer` is a
        // polymorphic belongsTo onto `customer` — a bare `contact` is refused by the graph.
        test.sessionCustomer = test.store.createRecord('customer', { name: 'Ada', customer_type: 'contact' });
        if (state) {
            test.currentUser.setOption('order:state:quote_1', state);
        }

        const quote = {
            id: 'quote_1',
            amount: 1200,
            meta: { preliminary_query: { payload: quotePayload } },
            get(path) {
                return emberGet(this, path);
            },
        };

        test.store.findRecord = (modelName, id) => {
            if (modelName === 'service-quote') {
                return Promise.resolve(quote);
            }
            if (modelName === 'file') {
                return Promise.resolve(test.store.push(test.store.normalize('file', { uuid: id, original_filename: `${id}.pdf` })));
            }
            return Promise.resolve(test.store.peekRecord(modelName, id));
        };

        if (checkoutResponse) {
            test.getResponses['service-quotes/stripe-checkout-session'] = checkoutResponse;
        }

        return quote;
    }

    /** The two places a restored order is usually routed between. */
    const RESTORED_PICKUP = { uuid: 'place_pickup', street1: 'Depot', location: { type: 'Point', coordinates: [103.8, 1.3] } };
    const RESTORED_DROPOFF = { uuid: 'place_dropoff', street1: 'Site', location: { type: 'Point', coordinates: [103.9, 1.4] } };

    test('returning from a checkout restores the order that was saved against the quote', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        givenCheckoutReturn(this, {
            state: {
                order_config_uuid: 'config_1',
                pod_required: true,
                pod_method: 'signature',
                notes: 'Leave at reception',
                customFieldValues: [{ id: 'cfv_1' }],
                files: ['file_1'],
                payload: {
                    entities: [{ uuid: 'entity_saved', name: 'Crate' }, { name: 'Loose box' }],
                },
            },
            quotePayload: { pickup: RESTORED_PICKUP, dropoff: RESTORED_DROPOFF },
        });

        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        assert.true(this.order.pod_required, 'the proof-of-delivery choice comes back');
        assert.strictEqual(this.order.pod_method, 'signature');
        assert.strictEqual(this.order.notes, 'Leave at reception', 'and so do the notes');

        const items = [...document.querySelectorAll('button')].filter((el) => el.textContent.includes('Edit Item'));
        assert.strictEqual(items.length, 2, 'both items are back — the one already saved and the one that never was');

        assert.deepEqual(
            [...this.order.files].map((file) => file.id),
            ['file_1'],
            'the files the customer had attached are fetched and reattached'
        );
        assert.dom('.leaflet-routing-container, .leaflet-overlay-pane').exists('and the route between the restored places is drawn again');
    });

    test('a multi-drop quote comes back as waypoints rather than a pickup and dropoff', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        givenCheckoutReturn(this, {
            state: { payload: {} },
            quotePayload: { waypoints: [RESTORED_PICKUP, RESTORED_DROPOFF] },
        });

        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        assert.dom('[data-test-drag-item]').exists({ count: 2 }, 'each restored waypoint gets its row back');
        const [multiDrop] = document.querySelectorAll('[role="checkbox"]');
        assert.strictEqual(multiDrop.getAttribute('aria-checked'), 'true', 'and the order is put back into multi-drop mode');
    });

    test('a completed checkout finishes the order and clears the state it was holding', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        givenCheckoutReturn(this, {
            state: { payload: {} },
            quotePayload: { pickup: RESTORED_PICKUP, dropoff: RESTORED_DROPOFF },
            checkoutResponse: { status: 'complete', purchaseRate: { uuid: 'rate_1', amount: 1200 } },
        });
        this.order.save = () => Promise.resolve(this.order);

        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        assert.deepEqual(this.removedParams, ['checkout_session_id'], 'the session id is taken back out of the URL');
        assert.strictEqual(this.order.purchase_rate_uuid, 'rate_1', 'the rate that was purchased is put on the order');
        assert.true(
            this.universeEvents.some(([name]) => name === 'fleet-ops.order.created'),
            'the order is created now that it is paid for'
        );
        assert.strictEqual(this.currentUser.getOption('order:state:quote_1'), undefined, 'and the saved state it was restored from is dropped');
        // Twice, and both are deliberate: `displayCompletingOrderDialog` clears whatever was up
        // before it puts the dialog there, and `checkForCheckoutSession` closes it at the end.
        assert.strictEqual(this.modalsDone, 2, 'the dialog is cleared before it goes up, and closed again once the order is made');
    });

    test('a restored item uploads a new photo straight away', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        givenCheckoutReturn(this, {
            state: { payload: { entities: [{ uuid: 'entity_saved', name: 'Crate' }] } },
            quotePayload: {},
        });

        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        const editItem = [...document.querySelectorAll('button')].find((el) => el.textContent.includes('Edit Item'));
        await click(editItem);

        const [, options] = this.modals.at(-1);
        assert.false(options.entity.get('isNew'), 'the restored item is a saved one, not a draft');

        options.uploadNewPhoto({ file: new Blob(['x']), queue: { remove: () => assert.ok(false, 'a saved item does not hold its photo back') } });

        assert.true(this.modalLoading, 'the modal shows it is working');
        assert.strictEqual(this.uploads.length, 1, 'the photo goes up immediately');
        assert.deepEqual(
            this.uploads[0].meta,
            { path: 'uploads/company_1/entities/entity_saved', subject_uuid: 'entity_saved', subject_type: 'fleet-ops:entity', type: 'entity_photo' },
            'filed against the entity it belongs to'
        );

        const uploaded = this.store.createRecord('file', { url: 'https://files.test/crate.png' });
        this.uploads[0].onSuccess(uploaded);
        assert.strictEqual(options.entity.get('photo_uuid'), uploaded.id, 'and the item takes the uploaded photo');
        assert.strictEqual(options.entity.get('photo_url'), 'https://files.test/crate.png');
        assert.false(this.modalLoading, 'the modal stops working');

        this.uploads[0].onError();
        assert.false(this.modalLoading, 'an upload that fails also stops it');
    });

    test('confirming the item form saves the item, but the held-back photo is never replayed', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        const addItem = [...document.querySelectorAll('button')].find((el) => el.textContent.includes('Add Item'));
        await click(addItem);
        await click([...document.querySelectorAll('button')].find((el) => el.textContent.includes('Edit Item')));

        const [, options] = this.modals.at(-1);
        const heldBack = { file: new Blob(['x']), queue: { remove: () => {} } };
        options.uploadNewPhoto(heldBack);

        const modal = this.owner.lookup('service:modals-manager');
        let saved = false;
        options.entity.save = () => {
            saved = true;
            return Promise.resolve(options.entity);
        };

        await options.confirm(modal);

        assert.true(saved, 'confirming saves the item');
        assert.strictEqual(modal.getOption('pendingFileUpload'), heldBack, 'the photo it was holding is still there');
        // DEFECTS #98: `modal.invoke('uploadNewPhoto', pendingFileUpload)` puts the file in
        // `invoke`'s second parameter, which is `modalId` — so no modal is found and the
        // callback is never reached. The photo the customer picked is silently dropped.
        assert.strictEqual(this.uploads.length, 0, 'but it is never uploaded — the replay call passes it as a modal id');
    });

    test('the last item cannot be removed, and a saved one is deleted rather than dropped', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        givenCheckoutReturn(this, {
            state: { payload: { entities: [{ name: 'Loose box' }, { uuid: 'entity_saved', name: 'Crate' }] } },
            quotePayload: {},
        });

        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        const removeButtons = () => [...document.querySelectorAll('button')].filter((el) => el.querySelector('.fa-xmark'));
        const itemRows = () => [...document.querySelectorAll('button')].filter((el) => el.textContent.includes('Edit Item')).length;
        assert.strictEqual(itemRows(), 2, 'two items to start with');

        // The saved one is second; removing it destroys the record rather than dropping the row.
        let destroyed = false;
        const savedEntity = this.store.peekRecord('entity', 'entity_saved');
        savedEntity.destroyRecord = () => {
            destroyed = true;
            return Promise.resolve(savedEntity);
        };
        await click(removeButtons().at(-1));
        assert.true(destroyed, 'a saved item is deleted on the server');
        assert.strictEqual(itemRows(), 2, 'and stays on screen until that delete comes back');
    });

    test('choosing a destination for an item records it against that item', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        givenCheckoutReturn(this, {
            state: { payload: { entities: [{ name: 'Loose box' }] } },
            quotePayload: { waypoints: [RESTORED_PICKUP, RESTORED_DROPOFF] },
        });

        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        const destination = document.querySelector('select.form-input-sm');
        assert.ok(destination, 'an item in a multi-drop order is asked which stop it is for');

        await fillIn(destination, 'place_dropoff');

        const editItem = [...document.querySelectorAll('button')].find((el) => el.textContent.includes('Edit Item'));
        await click(editItem);
        assert.strictEqual(this.modals.at(-1)[1].entity.get('destination_uuid'), 'place_dropoff', 'and the choice is kept on the item');
    });

    test('editing an item offers a photo upload, which differs for a saved item', async function (assert) {
        this.givenOrderConfigs({ id: 'config_1', key: 'transport', name: 'Transport' });
        await render(hbs`<Customer::CreateOrderForm @order={{this.order}} @map={{this.map}} />`);

        const addItem = [...document.querySelectorAll('button')].find((el) => el.textContent.includes('Add Item'));
        await click(addItem);

        const editItem = [...document.querySelectorAll('button')].find((el) => el.textContent.includes('Edit Item'));
        assert.ok(editItem, 'an added item can be edited');
        await click(editItem);

        const [name, options] = this.modals.at(-1);
        assert.strictEqual(name, 'modals/entity-form');
        assert.ok(options.entity, 'the item being edited is handed to the modal');

        // A new item holds its photo back until the item itself is saved.
        const newFileRemoved = [];
        options.uploadNewPhoto({ file: new Blob(['x']), queue: { remove: (f) => newFileRemoved.push(f) } });
        assert.strictEqual(this.uploads.length, 0, 'a new item does not upload yet');
        assert.strictEqual(newFileRemoved.length, 1, 'the file is held aside instead');
        assert.ok(options.entity.get('photo_url'), 'though it is shown straight away');
    });
});
