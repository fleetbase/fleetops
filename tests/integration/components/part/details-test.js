import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render, settled } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import stubFormInputs from 'dummy/tests/helpers/stub-form-inputs';

function field(name) {
    const label = findAll('.field-name').find((el) => el.textContent.trim() === name);
    if (!label) return null;
    const value = label.nextElementSibling;
    const copyable = value.querySelector('.click-to-copy--value');
    return (copyable ?? value).textContent.replace(/\s+/g, ' ').trim();
}

function panelTitles() {
    return findAll('.panel-title').map((el) => el.textContent.trim());
}

module('Integration | Component | part/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        stubFormInputs(this.owner);
    });

    test('it renders identity, inventory, pricing, supplier and description', async function (assert) {
        this.set('resource', {
            photo_url: null,
            name: 'Brake pad',
            public_id: 'part_1',
            sku: 'BP-100',
            type: 'consumable',
            barcode: '0123',
            serial_number: 'SN-1',
            manufacturer: 'Brembo',
            model: 'P85020',
            status: 'active',
            quantity_on_hand: 3,
            is_low_stock: true,
            is_in_stock: true,
            asset_name: 'Truck 1',
            unit_cost: 4500,
            msrp: 6000,
            total_value: 13500,
            currency: 'USD',
            vendor_name: 'Parts Co',
            warranty_name: 'One year',
            description: 'Front axle pads',
        });

        await render(hbs`<Part::Details @resource={{this.resource}} class="probe" />`);

        assert.dom('.next-content-panel-wrapper').hasClass('probe');
        assert.deepEqual(panelTitles(), ['Overview', 'Pricing', 'Supplier & Warranty', 'Description']);
        assert.ok(findAll('img')[0].getAttribute('src').startsWith('data:image/svg+xml'), 'no photo falls back to the placeholder');
        assert.strictEqual(field('ID'), 'part_1');
        assert.strictEqual(field('SKU'), 'BP-100');
        assert.strictEqual(field('Name'), 'Brake pad');
        assert.strictEqual(field('Type'), 'Consumable');
        assert.strictEqual(field('Barcode'), '0123');
        assert.strictEqual(field('Serial Number'), 'SN-1');
        assert.strictEqual(field('Manufacturer'), 'Brembo');
        assert.strictEqual(field('Model'), 'P85020');
        assert.strictEqual(field('Status'), 'Active');
        assert.strictEqual(field('Quantity on Hand'), '3 Low Stock');
        assert.strictEqual(field('Fitted To'), 'Truck 1');
        assert.strictEqual(field('Unit Cost'), '$45.00');
        assert.strictEqual(field('MSRP'), '$60.00');
        assert.strictEqual(field('Total Inventory Value'), '$135.00');
        assert.strictEqual(field('Currency'), 'USD');
        assert.strictEqual(field('Supplier / Vendor'), 'Parts Co');
        assert.strictEqual(field('Warranty'), 'One year');
        assert.dom().doesNotIncludeText('Front axle pads', 'the description panel starts collapsed');
        assert.dom('[data-test-custom-fields]').exists();

        this.set('resource', { ...this.resource, is_low_stock: false, vendor_name: null, asset_name: null, description: null });
        await settled();
        assert.strictEqual(field('Quantity on Hand'), '3 In Stock');
        assert.strictEqual(field('Fitted To'), null);
        assert.strictEqual(field('Supplier / Vendor'), null);
        assert.deepEqual(panelTitles(), ['Overview', 'Pricing', 'Supplier & Warranty']);

        this.set('resource', { ...this.resource, is_in_stock: false, warranty_name: null, photo_url: 'https://cdn.example.com/pad.png' });
        await settled();
        assert.strictEqual(field('Quantity on Hand'), '3 Out of Stock');
        assert.deepEqual(panelTitles(), ['Overview', 'Pricing']);
        assert.dom('img').hasAttribute('src', 'https://cdn.example.com/pad.png');
    });
});
