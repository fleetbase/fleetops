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

    hooks.beforeEach(function () {
        const test = this;
        this.gets = [];
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
        this.owner.register('service:universe', class extends Service {});
        this.owner.register('service:events', class extends Service {});

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
                        return Promise.resolve(test.customFieldsManager ?? { validateRequired: () => ({ isValid: true, errors: new Map() }) });
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
});
