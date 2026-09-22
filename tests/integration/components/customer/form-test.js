import Component from '@glimmer/component';
import Service from '@ember/service';
import { setComponentTemplate } from '@ember/component';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

let modelSelectQueries;

class ModelSelectStub extends Component {
    constructor() {
        super(...arguments);
        modelSelectQueries.push({ modelName: this.args.modelName, query: this.args.query });
    }
}

class ExtensionManagerStub extends Service {
    installed = [];

    isInstalled(name) {
        return this.installed.includes(name);
    }

    ensureEngineLoaded() {
        return Promise.resolve();
    }
}

function makeResource(initial = {}) {
    return {
        ...initial,
        set(key, value) {
            this[key] = value;
        },
        setProperties(values) {
            Object.assign(this, values);
        },
    };
}

module('Integration | Component | customer/form', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        modelSelectQueries = [];

        this.owner.register('service:universe/extension-manager', ExtensionManagerStub);
        this.owner.register('component:model-select', setComponentTemplate(hbs`<div data-test-model-select={{@modelName}}></div>`, ModelSelectStub));
    });

    test('it does not offer a user account selector', async function (assert) {
        this.set('customer', makeResource({ isNew: false }));

        await render(hbs`<Customer::Form @resource={{this.customer}} />`);

        assert.notOk(
            modelSelectQueries.find((entry) => entry.modelName === 'user'),
            'login accounts are managed by the server'
        );
    });

    test('email and phone are read-only for a staff-linked customer', async function (assert) {
        this.set('customer', makeResource({ isNew: false, is_staff_linked: true, email: 'staff@example.com' }));

        await render(hbs`<Customer::Form @resource={{this.customer}} />`);

        assert.dom('input[placeholder="Email"]').isDisabled();
        assert.dom('[data-test-staff-linked-helper]').exists();
    });

    test('the welcome email option is offered when creating a customer with the portal installed', async function (assert) {
        this.owner.lookup('service:universe/extension-manager').installed = ['@fleetbase/customer-portal-engine'];
        this.set('customer', makeResource({ isNew: true }));

        await render(hbs`<Customer::Form @resource={{this.customer}} />`);

        assert.dom('input[type="checkbox"]').exists({ count: 1 });
        assert.dom('input[type="checkbox"]').isNotChecked('the welcome email is opt in');
        assert.dom().includesText('Send customer portal welcome email');
    });

    test('opting in stores the welcome email flag on the customer portal meta', async function (assert) {
        this.owner.lookup('service:universe/extension-manager').installed = ['@fleetbase/customer-portal-engine'];
        this.set('customer', makeResource({ isNew: true }));

        await render(hbs`<Customer::Form @resource={{this.customer}} />`);
        await click('input[type="checkbox"]');

        assert.deepEqual(this.customer.meta, { customer_portal: { send_welcome_email: true } });
    });

    test('the welcome email option is hidden without the customer portal extension', async function (assert) {
        this.set('customer', makeResource({ isNew: true }));

        await render(hbs`<Customer::Form @resource={{this.customer}} />`);

        assert.dom('input[type="checkbox"]').doesNotExist();
        assert.dom().doesNotIncludeText('Send customer portal welcome email');
    });

    test('the welcome email option is hidden when editing an existing customer', async function (assert) {
        this.owner.lookup('service:universe/extension-manager').installed = ['@fleetbase/customer-portal-engine'];
        this.set('customer', makeResource({ isNew: false }));

        await render(hbs`<Customer::Form @resource={{this.customer}} />`);

        assert.dom('input[type="checkbox"]').doesNotExist();
    });
});
