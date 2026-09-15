import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { initialize } from '@fleetbase/fleetops-engine/instance-initializers/register-fleetops-resource-descriptors';

/**
 * The one-line identity cells: a small image with the status dot, the name,
 * and the inline badges, all on one line and never a status badge.
 */
module('Integration | Component | cell resource identities', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        initialize(this.owner);
    });

    test('vehicle identity puts the plate and the assigned driver on the one line, with no status text', async function (assert) {
        this.set('vehicle', {
            resourceType: 'vehicle',
            displayName: 'Truck 104',
            plate_number: 'ABC-123',
            driver_name: 'Ada Driver',
            status: 'available',
            online: true,
            photo_url: 'https://example.test/truck.png',
        });

        await render(hbs`<Cell::VehicleIdentity @row={{this.vehicle}} @column={{hash label="Vehicle"}} />`);

        assert.dom('[data-test-identity-cell]').exists({ count: 1 });
        assert.dom('[data-test-identity-label]').hasText('Truck 104');
        assert.dom('[data-test-resource-identity-image]').hasClass('h-5');
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('text-green-500');
        assert.dom('[data-test-resource-identity-meta-badge][data-badge-key="plate"]').hasText('ABC-123');
        assert.dom('[data-test-resource-identity-meta-badge][data-badge-key="driver"]').hasText('Ada Driver');
        assert.dom('[data-test-resource-identity-status-badge]').doesNotExist('the status badge is gone');
        assert.dom('[data-test-identity-cell]').doesNotIncludeText('Available');
        assert.dom('[data-test-resource-identity-meta-row]').doesNotExist('no second line');
    });

    test('driver identity shows the assigned vehicle badge and tones the dot by status', async function (assert) {
        this.set('driver', { resourceType: 'driver', name: 'Ada Driver', phone: '+15551234567', status: 'suspended', vehicle_name: 'Truck 10' });

        await render(hbs`<Cell::DriverIdentity @row={{this.driver}} @column={{hash}} />`);

        assert.dom('[data-test-identity-label]').hasText('Ada Driver');
        assert.dom('[data-test-resource-identity-meta-badge][data-badge-key="vehicle"]').hasText('Truck 10');
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('text-red-500');
        assert.dom('[data-test-identity-cell]').doesNotIncludeText('+15551234567');
        assert.dom('[data-test-identity-cell]').doesNotIncludeText('Suspended');
    });

    test('a badge that points back at the row itself is dropped', async function (assert) {
        this.set('row', { id: 'vehicle_1', driver_name: 'Ada Driver', driver_uuid: 'driver_9', vehicle_name: 'Truck 1' });
        this.set('driver', { resourceType: 'driver', id: 'driver_9', name: 'Ada Driver', vehicle: { id: 'vehicle_1', displayName: 'Truck 1' } });

        await render(hbs`<Cell::DriverIdentity @row={{this.row}} @column={{hash resourcePath=this.resolveDriver}} />`);

        assert.dom('[data-test-identity-label]').hasText('Ada Driver');
        assert.dom('[data-test-resource-identity-meta-badge][data-badge-key="vehicle"]').doesNotExist('the vehicle row does not repeat itself in its driver cell');
    });

    hooks.beforeEach(function () {
        this.set('resolveDriver', () => this.driver);
    });

    test('the column click chain runs and stops propagation', async function (assert) {
        const calls = [];
        this.set('vehicle', { resourceType: 'vehicle', displayName: 'Truck 104' });
        this.set('rowClick', () => calls.push('row'));
        this.set('column', { action: (resource) => calls.push(['action', resource]), onClick: (resource) => calls.push(['onClick', resource]) });
        this.set('onClick', (resource) => calls.push(['arg', resource]));

        await render(hbs`<div {{on "click" this.rowClick}}><Cell::VehicleIdentity @row={{this.vehicle}} @column={{this.column}} @onClick={{this.onClick}} /></div>`);
        await click('[data-test-identity-button]');

        assert.deepEqual(
            calls.map((call) => (Array.isArray(call) ? call[0] : call)),
            ['arg', 'onClick', 'action'],
            'the cell handlers run and the row action does not'
        );
        assert.strictEqual(calls[0][1], this.vehicle);
    });

    test('device identity keeps the attached-to badge and equipment, part and telematic identities render their tiles', async function (assert) {
        this.set('device', { resourceType: 'device', name: 'GPS 1', imei: '123', attached_to_name: 'Truck 1', connection_status: 'online' });
        this.set('equipment', { resourceType: 'equipment', name: 'Lift', serial_number: 'SN-1', is_equipped: true });
        this.set('part', { resourceType: 'part', name: 'Filter', sku: 'FLT-1', is_low_stock: true, quantity_on_hand: 2 });
        this.set('telematic', { resourceType: 'telematic', name: 'Samsara', provider: 'samsara' });

        await render(hbs`
            <Cell::DeviceIdentity @row={{this.device}} @column={{hash}} />
            <Cell::EquipmentIdentity @row={{this.equipment}} @column={{hash}} />
            <Cell::PartIdentity @row={{this.part}} @column={{hash}} />
            <Cell::TelematicIdentity @row={{this.telematic}} @column={{hash}} />
        `);

        assert.dom('[data-resource-type="device"] [data-badge-key="attached-to"]').hasText('Truck 1');
        assert.dom('[data-resource-type="device"] [data-test-resource-identity-status-dot]').hasClass('text-green-500');
        assert.dom('[data-resource-type="equipment"] [data-test-resource-identity-status-dot]').hasClass('text-green-500');
        assert.dom('[data-resource-type="part"] [data-test-resource-identity-status-dot]').hasClass('text-yellow-500');
        assert.dom('[data-resource-type="part"] [data-badge-key="quantity"]').hasText('2 on hand');
        assert.dom('[data-resource-type="telematic"] [data-test-identity-label]').hasText('Samsara');
    });

    test('a missing resource renders the empty text', async function (assert) {
        this.set('row', { driver_name: null });
        this.set('column', { resourcePath: () => null });

        await render(hbs`<Cell::DriverIdentity @row={{this.row}} @column={{this.column}} />`);
        assert.dom('[data-test-identity-empty-text]').hasText('-');

        this.set('column', { resourcePath: () => null, emptyText: 'Unassigned' });
        await render(hbs`<Cell::DriverIdentity @row={{this.row}} @column={{this.column}} />`);
        assert.dom('[data-test-identity-empty-text]').hasText('Unassigned');
    });
});
