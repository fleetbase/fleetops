import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

module('Integration | Component | vendor/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        registerTemplateOnly(this.owner, 'custom-field/yield', hbs`<div data-test-custom-fields></div>`);
        registerTemplateOnly(this.owner, 'country-name', hbs`<span data-test-country>{{@country}}</span>`);
    });

    test('it renders a regular vendor with its contact details', async function (assert) {
        this.set('resource', { name: 'Acme', email: 'ops@acme.test', phone: '+65 1', website_url: 'https://acme.test', country: 'SG', status: 'active', address: '1 Harbour Road' });

        await render(hbs`<Vendor::Details @resource={{this.resource}} />`);

        assert.dom(this.element).includesText('Acme').includesText('ops@acme.test').includesText('+65 1').includesText('https://acme.test').includesText('1 Harbour Road');
        assert.dom('[data-test-country]').hasText('SG');
        assert.dom('.status-badge').hasText('Active');
        assert.dom('[data-test-custom-fields]').exists();

        this.set('resource', {});
        await render(hbs`<Vendor::Details @resource={{this.resource}} />`);
        assert.strictEqual(findAll('.field-value').filter((element) => element.textContent.trim() === '-').length, 5, 'empty fields are dashed');
    });

    test('an integrated vendor renders the integration details instead', async function (assert) {
        this.set('resource', { isIntegratedVendor: true, provider_settings: { name: 'Shippo', code: 'shippo' }, sandbox: true, host: 'https://api.goshippo.com' });

        await render(hbs`<Vendor::Details @resource={{this.resource}} />`);

        assert.dom('h3').hasText('Shippo');
        assert.dom(this.element).includesText('https://api.goshippo.com').includesText('Yes');
        assert.dom('[data-test-custom-fields]').doesNotExist('the regular details are not rendered');
    });
});
