import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

module('Integration | Component | customer/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        registerTemplateOnly(this.owner, 'custom-field/yield', hbs`<div data-test-custom-fields></div>`);
    });

    test('it renders the account and detail panels for the customer', async function (assert) {
        this.set('resource', { name: 'Ada', title: 'Ops Lead', email: 'ada@example.com', phone: '+65 1', internal_id: 'INT-1', type: 'customer_contact', address: '1 Harbour Road' });

        await render(hbs`<Customer::Details @resource={{this.resource}} />`);

        assert.dom(this.element).includesText('Ada').includesText('Ops Lead').includesText('INT-1').includesText('1 Harbour Road');
        assert.strictEqual(findAll('.click-to-copy--value').filter((element) => element.textContent.trim() === 'ada@example.com').length, 2, 'the email is copyable in both panels');
        assert.strictEqual(findAll('.click-to-copy--value').filter((element) => element.textContent.trim() === '+65 1').length, 2, 'the phone is copyable in both panels');
        assert.dom('.status-badge').hasText('Customer Contact');
        assert.dom('[data-test-custom-fields]').exists();
    });

    test('empty fields are dashed', async function (assert) {
        this.set('resource', {});

        await render(hbs`<Customer::Details @resource={{this.resource}} />`);

        assert.strictEqual(findAll('.field-value, .click-to-copy--value').filter((element) => element.textContent.trim() === '-').length, 9);
    });
});
