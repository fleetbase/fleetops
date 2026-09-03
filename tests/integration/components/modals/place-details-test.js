import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

function place(overrides = {}) {
    return {
        name: 'Warehouse',
        street1: '1 Harbour Road',
        neighborhood: 'Docklands',
        building: 'Block C',
        security_access_code: '1234',
        city: 'Singapore',
        province: 'Central',
        country: 'SG',
        phone: '+6512345678',
        email: 'ops@example.com',
        vendor_name: 'Acme Logistics',
        latitude: 1.3,
        longitude: 103.8,
        coordinates: [1.3, 103.8],
        location: { type: 'Point', coordinates: [103.8, 1.3] },
        ...overrides,
    };
}

module('Integration | Component | modals/place-details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        registerTemplateOnly(this.owner, 'country-name', hbs`<span data-test-country>{{@country}}</span>`);
        this.calls = [];
        this.set('options', {
            title: 'Place',
            place: place({ vendor_uuid: 'vendor_1' }),
            viewVendor: () => this.calls.push('viewVendor'),
            viewPlaceOnMap: () => this.calls.push('viewPlaceOnMap'),
        });
    });

    test('it renders the place on a map with its address and links to the vendor', async function (assert) {
        await render(hbs`<Modals::PlaceDetails @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom('.leaflet-container').exists();
        assert.dom('.leaflet-marker-icon').exists();
        for (const text of ['Warehouse', '1 Harbour Road', 'Docklands', 'Block C', '1234', 'Singapore', 'Central', '+6512345678', 'ops@example.com']) {
            assert.dom(this.element).includesText(text);
        }
        assert.dom('[data-test-country]').hasText('SG');

        const vendorLink = findAll('a').find((element) => element.textContent.includes('Acme Logistics'));
        assert.ok(vendorLink, 'a vendor with an id is a link');
        await click(vendorLink);
        await click(findAll('a').find((element) => element.textContent.includes('View')));
        assert.deepEqual(this.calls, ['viewVendor', 'viewPlaceOnMap']);
    });

    test('a vendor without an id is plain text and empty fields are dashed', async function (assert) {
        this.set('options', { ...this.options, place: place({ vendor_uuid: null, vendor_name: 'Walk-in Vendor', email: null, phone: null }) });

        await render(hbs`<Modals::PlaceDetails @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom(this.element).includesText('Walk-in Vendor');
        assert.notOk(
            findAll('a').find((element) => element.textContent.includes('Walk-in Vendor')),
            'no link without a vendor id'
        );
        assert.strictEqual(findAll('.field-value').filter((element) => element.textContent.trim() === '-').length, 2, 'phone and email are dashed');
    });
});
