import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | vendor/panel-header', function (hooks) {
    setupRenderingTest(hooks);

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
        assert.dom().includesText('100 Fleet St');
        assert.dom().includesText('https://example.test');
        assert.dom('img').hasClass('rounded-md');
    });

    test('it falls back when vendor values are missing', async function (assert) {
        this.set('resource', {
            public_id: 'vendor_123',
        });

        await render(hbs`<Vendor::PanelHeader @resource={{this.resource}} />`);

        assert.dom().includesText('vendor_123');
        assert.dom().includesText('Active');
    });
});
