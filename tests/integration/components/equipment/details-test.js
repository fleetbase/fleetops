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

module('Integration | Component | equipment/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        stubFormInputs(this.owner);
    });

    test('it renders identity, assignment, financials and warranty', async function (assert) {
        this.set('resource', {
            photo_url: 'https://cdn.example.com/forklift.png',
            name: 'Forklift 7',
            public_id: 'equipment_1',
            code: 'EQ-7',
            type: 'lift_truck',
            status: 'active',
            serial_number: 'SN-7',
            manufacturer: 'Toyota',
            model: '8FG',
            is_equipped: true,
            equipped_to_name: 'Truck 1',
            purchase_price: 250000,
            currency: 'USD',
            purchased_at: new Date(2024, 0, 15),
            age_in_days: 600,
            depreciated_value: 200000,
            warranty_name: 'Two year parts',
        });

        await render(hbs`<Equipment::Details @resource={{this.resource}} class="probe" />`);

        assert.dom('.next-content-panel-wrapper').hasClass('probe');
        assert.deepEqual(panelTitles(), ['Overview', 'Financials', 'Warranty']);
        assert.dom('img').hasAttribute('src', 'https://cdn.example.com/forklift.png');
        assert.strictEqual(field('ID'), 'equipment_1');
        assert.strictEqual(field('Code'), 'EQ-7');
        assert.strictEqual(field('Name'), 'Forklift 7');
        assert.strictEqual(field('Type'), 'Lift Truck');
        assert.strictEqual(field('Status'), 'Active');
        assert.strictEqual(field('Serial Number'), 'SN-7');
        assert.strictEqual(field('Manufacturer'), 'Toyota');
        assert.strictEqual(field('Model'), '8FG');
        assert.strictEqual(field('Equipped Status'), 'Equipped');
        assert.strictEqual(field('Equipped To'), 'Truck 1');
        assert.strictEqual(field('Purchase Price'), '$2,500.00');
        assert.strictEqual(field('Purchased At'), '15 Jan 2024');
        assert.strictEqual(field('Age'), '600 days');
        assert.strictEqual(field('Depreciated Value'), '$2,000.00');
        assert.strictEqual(field('Currency'), 'USD');
        assert.strictEqual(field('Warranty'), 'Two year parts');
        assert.dom('[data-test-custom-fields]').exists();

        this.set('resource', { ...this.resource, photo_url: null, is_equipped: false, warranty_name: null });
        await settled();

        assert.deepEqual(panelTitles(), ['Overview', 'Financials'], 'no warranty panel without a warranty');
        assert.ok(findAll('img')[0].getAttribute('src').startsWith('data:image/svg+xml'), 'falls back to the placeholder image');
        assert.strictEqual(field('Equipped Status'), 'Not Equipped');
        assert.strictEqual(field('Equipped To'), null);
    });
});
