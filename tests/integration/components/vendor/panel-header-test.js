import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

module('Integration | Component | vendor/panel-header', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        // ember-ui's CountryName looks the code up over the network 300ms after it renders.
        registerTemplateOnly(this.owner, 'country-name', hbs`<span data-test-country>{{@country}}</span>`);
    });

    test('it renders compact vendor identity', async function (assert) {
        this.set('resource', {
            name: 'Acme Transport',
            logo_url: 'https://example.test/vendor.png',
            status: 'active',
            business_id: 'BRN-100',
            type: 'carrier',
            email: 'ops@example.test',
            phone: '+18005550100',
            country: 'US',
            address_street: '100 Fleet St',
            website_url: 'https://example.test',
        });

        await render(hbs`<Vendor::PanelHeader @resource={{this.resource}} />`);

        assert.dom().includesText('Acme Transport');
        assert.dom().includesText('Active');
        assert.dom().includesText('BRN-100');
        assert.dom().includesText('Carrier');
        assert.dom().includesText('ops@example.test');
        assert.dom().includesText('+18005550100');
        assert.dom('[data-test-country]').hasText('US');
        assert.dom().includesText('100 Fleet St');
        assert.dom().includesText('https://example.test');
        assert.dom('img').hasClass('rounded-md');
        assert.dom('img').hasAttribute('src', 'https://example.test/vendor.png');
    });

    test('a photo wins over the logo and the address falls back', async function (assert) {
        this.set('resource', {
            name: 'Acme Transport',
            photo_url: 'https://example.test/photo.png',
            logo_url: 'https://example.test/vendor.png',
            address: '1 Old Road',
        });

        await render(hbs`<Vendor::PanelHeader @resource={{this.resource}} />`);

        assert.dom('img').hasAttribute('src', 'https://example.test/photo.png');
        assert.dom().includesText('1 Old Road');
        assert.dom('[data-test-country]').doesNotExist();
    });

    test('it falls back when vendor values are missing', async function (assert) {
        this.set('resource', {
            public_id: 'vendor_123',
        });

        await render(hbs`<Vendor::PanelHeader @resource={{this.resource}} />`);

        assert.dom().includesText('vendor_123');
        assert.dom().includesText('Active');

        this.set('resource', {});
        await render(hbs`<Vendor::PanelHeader @resource={{this.resource}} />`);
        assert.dom('h1').hasText('-');
    });
});
