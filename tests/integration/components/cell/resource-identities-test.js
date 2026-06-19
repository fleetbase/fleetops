import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | cell resource identities', function (hooks) {
    setupRenderingTest(hooks);

    test('driver identity renders status first and assigned vehicle without phone', async function (assert) {
        this.set('driver', {
            name: 'Ada Driver',
            phone: '+15551234567',
            status: 'active',
            online: true,
            vehicle_name: 'Truck 10',
        });

        await render(hbs`<Cell::DriverIdentity @row={{this.driver}} @column={{hash}} />`);

        assert.dom(this.element).includesText('Ada Driver');
        assert.dom(this.element).includesText('Active');
        assert.dom(this.element).includesText('Truck 10');
        assert.dom(this.element).doesNotIncludeText('+15551234567');
        assert.dom('[data-test-resource-identity-status-badge]').hasClass('order-first');
    });

    test('driver identity compact mode renders image, name, and assigned vehicle only', async function (assert) {
        assert.expect(18);

        this.set('driver', {
            name: 'Compact Driver',
            phone: '+15551234567',
            status: 'available',
            photo_url: 'https://example.test/driver.png',
            vehicle_name: 'Truck 10',
        });
        this.set('onClick', (driver) => {
            assert.strictEqual(driver, this.driver, 'compact click receives the resolved driver resource');
        });

        await render(hbs`<Cell::DriverIdentity @row={{this.driver}} @column={{hash compact=true action=this.onClick}} />`);

        assert.dom('[data-test-driver-identity-compact]').exists();
        assert.dom('[data-test-driver-identity-compact]').includesText('Compact Driver');
        assert.dom('[data-test-driver-identity-compact] img').hasClass('h-5');
        assert.dom('[data-test-driver-identity-compact] img').hasClass('w-5');
        assert.dom('[data-test-driver-identity-compact] .text-sm').exists();
        assert.dom('[data-test-driver-identity-compact] .font-semibold').doesNotExist();
        assert.dom('[data-test-resource-identity-status-dot]').exists();
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('fa-2xs');
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('left-0');
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('top-0');
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('-ml-1');
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('-mt-1');
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('text-green-500');
        assert.dom('[data-test-resource-identity-meta-badge]').exists({ count: 1 });
        assert.dom('[data-test-resource-identity-meta-badge]').hasText('Truck 10');
        assert.dom('[data-test-resource-identity-status-badge]').doesNotExist();
        assert.dom(this.element).doesNotIncludeText('+15551234567');

        await click('[data-test-driver-identity-compact]');
    });

    test('driver identity compact mode renders assigned vehicle from column context', async function (assert) {
        this.set('vehicle', {
            displayName: 'Parent Truck',
            driver: {
                name: 'Context Driver',
            },
        });
        this.set('column', {
            compact: true,
            resourcePath: (vehicle) => vehicle.driver,
            assignedVehicleLabel: (_driver, vehicle) => vehicle.displayName,
        });

        await render(hbs`<Cell::DriverIdentity @row={{this.vehicle}} @column={{this.column}} />`);

        assert.dom('[data-test-driver-identity-compact]').exists();
        assert.dom('[data-test-driver-identity-compact]').includesText('Context Driver');
        assert.dom('[data-test-resource-identity-meta-badge]').exists({ count: 1 });
        assert.dom('[data-test-resource-identity-meta-badge]').hasText('Parent Truck');
    });

    test('device identity renders imei badge and compact status only', async function (assert) {
        this.set('device', {
            displayName: 'Device 42',
            imei: 'IMEI-42',
            serial_number: 'SER-42',
            attached_to_name: 'Truck 42',
            telematic_name: 'AFAQY',
            connection_status: 'online',
            is_online: true,
        });

        await render(hbs`<Cell::DeviceIdentity @row={{this.device}} @column={{hash}} />`);

        assert.dom(this.element).includesText('Device 42');
        assert.dom(this.element).includesText('IMEI-42');
        assert.dom(this.element).includesText('Online');
        assert.dom('[data-test-resource-identity-status-badge]').hasText('Online');
        assert.dom(this.element).doesNotIncludeText('SER-42');
        assert.dom(this.element).doesNotIncludeText('Truck 42');
        assert.dom(this.element).doesNotIncludeText('AFAQY');
    });

    test('device identity can suppress status text and badge', async function (assert) {
        this.set('device', {
            displayName: 'Device 42',
            imei: 'IMEI-42',
            connection_status: 'offline',
            is_online: false,
        });

        await render(hbs`<Cell::DeviceIdentity @row={{this.device}} @column={{hash showStatus=false}} />`);

        assert.dom(this.element).includesText('Device 42');
        assert.dom(this.element).includesText('IMEI-42');
        assert.dom(this.element).doesNotIncludeText('Offline');
        assert.dom('[data-test-resource-identity-status-badge]').doesNotExist();
    });

    test('device identity renders provider identifier badge when imei is missing', async function (assert) {
        this.set('device', {
            displayName: 'Device 99',
            device_id: 'BX-025',
            ident: '867747078951793',
        });

        await render(hbs`<Cell::DeviceIdentity @row={{this.device}} @column={{hash showStatus=false}} />`);

        assert.dom(this.element).includesText('Device 99');
        assert.dom('[data-test-resource-identity-meta-badge]').hasText('BX-025');
    });

    test('device identity compact mode renders image and name only', async function (assert) {
        assert.expect(17);

        this.set('device', {
            displayName: 'Device Compact',
            imei: 'IMEI-COMPACT',
            connection_status: 'online',
            photo_url: 'https://example.test/device.png',
        });
        this.set('onClick', (device) => {
            assert.strictEqual(device, this.device, 'compact click receives the resolved device resource');
        });

        await render(hbs`<Cell::DeviceIdentity @row={{this.device}} @column={{hash compact=true showStatus=false action=this.onClick}} />`);

        assert.dom('[data-test-device-identity-compact]').exists();
        assert.dom('[data-test-device-identity-compact]').includesText('Device Compact');
        assert.dom('[data-test-device-identity-compact] img').hasClass('h-5');
        assert.dom('[data-test-device-identity-compact] img').hasClass('w-5');
        assert.dom('[data-test-device-identity-compact] .text-sm').exists();
        assert.dom('[data-test-device-identity-compact] .font-semibold').doesNotExist();
        assert.dom('[data-test-resource-identity-status-dot]').exists();
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('fa-2xs');
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('left-0');
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('top-0');
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('-ml-1');
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('-mt-1');
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('text-green-500');
        assert.dom('[data-test-resource-identity-meta-badge]').doesNotExist();
        assert.dom('[data-test-resource-identity-status-badge]').doesNotExist();
        assert.dom(this.element).doesNotIncludeText('IMEI-COMPACT');

        await click('[data-test-device-identity-compact]');
    });

    test('vehicle identity renders plate as badge metadata and toggles status badge by column config', async function (assert) {
        this.set('vehicle', {
            displayName: 'Truck 42',
            plate_number: 'GBB-1042',
            status: 'available',
            online: true,
        });

        await render(hbs`<Cell::VehicleIdentity @row={{this.vehicle}} @column={{hash showStatusBadge=false}} />`);

        assert.dom(this.element).includesText('Truck 42');
        assert.dom('[data-test-resource-identity-meta-badge]').exists({ count: 1 });
        assert.dom('[data-test-resource-identity-meta-badge]').hasText('GBB-1042');
        assert.dom('[data-test-resource-identity-status-badge]').doesNotExist();

        await render(hbs`<Cell::VehicleIdentity @row={{this.vehicle}} @column={{hash showStatusBadge=true}} />`);

        assert.dom('[data-test-resource-identity-status-badge]').exists();
        assert.dom('[data-test-resource-identity-status-badge]').hasText('Available');
    });

    test('vehicle identity can suppress status text and badge', async function (assert) {
        this.set('vehicle', {
            displayName: 'Truck 42',
            plate_number: 'GBB-1042',
            status: 'available',
        });

        await render(hbs`<Cell::VehicleIdentity @row={{this.vehicle}} @column={{hash showStatus=false}} />`);

        assert.dom(this.element).includesText('Truck 42');
        assert.dom(this.element).includesText('GBB-1042');
        assert.dom(this.element).doesNotIncludeText('Available');
        assert.dom('[data-test-resource-identity-status-badge]').doesNotExist();
    });

    test('vehicle identity compact mode renders image, name, and assigned driver only', async function (assert) {
        assert.expect(19);

        this.set('vehicle', {
            displayName: 'Compact Truck',
            plate_number: 'GBB-1042',
            status: 'available',
            photo_url: 'https://example.test/vehicle.png',
            driver_name: 'Ada Driver',
        });
        this.set('onClick', (vehicle) => {
            assert.strictEqual(vehicle, this.vehicle, 'compact click receives the resolved vehicle resource');
        });

        await render(hbs`<Cell::VehicleIdentity @row={{this.vehicle}} @column={{hash compact=true action=this.onClick}} />`);

        assert.dom('[data-test-vehicle-identity-compact]').exists();
        assert.dom('[data-test-vehicle-identity-compact]').includesText('Compact Truck');
        assert.dom('[data-test-vehicle-identity-compact] img').hasClass('h-5');
        assert.dom('[data-test-vehicle-identity-compact] img').hasClass('w-5');
        assert.dom('[data-test-vehicle-identity-compact] .text-sm').exists();
        assert.dom('[data-test-vehicle-identity-compact] .font-semibold').doesNotExist();
        assert.dom('[data-test-resource-identity-status-dot]').exists();
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('fa-2xs');
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('left-0');
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('top-0');
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('-ml-1');
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('-mt-1');
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('text-green-500');
        assert.dom('[data-test-resource-identity-meta-badge]').exists({ count: 1 });
        assert.dom('[data-test-resource-identity-meta-badge]').hasText('Ada Driver');
        assert.dom('[data-test-resource-identity-status-badge]').doesNotExist();
        assert.dom(this.element).doesNotIncludeText('GBB-1042');
        assert.dom(this.element).doesNotIncludeText('Available');

        await click('[data-test-vehicle-identity-compact]');
    });

    test('vehicle identity renders fallback vehicle number badge for attached-device rows', async function (assert) {
        this.set('vehicle', {
            displayName: 'Attached Vehicle',
            vehicle_number: 'vehicle_123',
        });

        await render(hbs`<Cell::VehicleIdentity @row={{this.vehicle}} @column={{hash showStatusBadge=false}} />`);

        assert.dom('[data-test-resource-identity-meta-badge]').exists({ count: 1 });
        assert.dom('[data-test-resource-identity-meta-badge]').hasText('vehicle_123');
        assert.dom('[data-test-resource-identity-status-badge]').doesNotExist();
    });

    test('equipment identity renders type and serial/code/public id badges only', async function (assert) {
        this.set('equipment', {
            name: 'Generator',
            type: 'generator',
            serial_number: 'SN-900',
            code: 'EQ-900',
            public_id: 'equipment_public',
            status: 'maintenance',
        });

        await render(hbs`<Cell::EquipmentIdentity @row={{this.equipment}} @column={{hash}} />`);

        assert.dom(this.element).includesText('Generator');
        assert.dom(this.element).includesText('generator');
        assert.dom(this.element).includesText('SN-900');
        assert.dom(this.element).doesNotIncludeText('Maintenance');
        assert.dom('[data-test-resource-identity-meta-badge]').exists({ count: 2 });
    });

    test('part identity renders type and inventory status as badge-style metadata', async function (assert) {
        this.set('part', {
            name: 'Brake Pad',
            type: 'brake',
            quantity_on_hand: 2,
            is_low_stock: true,
            is_in_stock: true,
        });

        await render(hbs`<Cell::PartIdentity @row={{this.part}} @column={{hash}} />`);

        assert.dom(this.element).includesText('Brake Pad');
        assert.dom(this.element).includesText('brake');
        assert.dom(this.element).includesText('Low Stock');
        assert.dom(this.element).doesNotIncludeText('2 on hand');
        assert.dom('[data-test-resource-identity-meta-badge]').exists({ count: 2 });
    });

    test('assigned identity cells render only default empty text when resource is missing', async function (assert) {
        this.set('vehicleRow', {
            displayName: 'Mercedes 1025',
            status: 'available',
            driver_name: null,
        });
        this.set('driverRow', {
            name: 'Ken Driver',
            status: 'available',
            vehicle_name: null,
        });
        this.set('missingDriverColumn', {
            resourcePath: () => null,
        });
        this.set('missingVehicleColumn', {
            resourcePath: () => null,
        });

        await render(hbs`
            <Cell::DriverIdentity @row={{this.vehicleRow}} @column={{this.missingDriverColumn}} />
            <Cell::VehicleIdentity @row={{this.driverRow}} @column={{this.missingVehicleColumn}} />
        `);

        assert.dom(this.element).doesNotIncludeText('Mercedes 1025');
        assert.dom(this.element).doesNotIncludeText('Ken Driver');
        assert.dom('[data-test-identity-empty-text]').exists({ count: 2 });
        assert.dom('[data-test-identity-empty-text]').hasText('- -');
        assert.dom('.table-cell-resource-identity').doesNotExist();
        assert.dom('[data-test-resource-identity-image]').doesNotExist();
        assert.dom('[data-test-resource-identity-status-dot]').doesNotExist();
        assert.dom('[data-test-resource-identity-meta-badge]').doesNotExist();
        assert.dom('[data-test-resource-identity-status-badge]').doesNotExist();
    });

    test('assigned identity cells support custom empty text', async function (assert) {
        this.set('vehicleRow', {
            displayName: 'Mercedes 1025',
            status: 'available',
            driver_name: null,
        });
        this.set('missingDriverColumn', {
            resourcePath: () => null,
            emptyText: 'No driver assigned',
        });

        await render(hbs`<Cell::DriverIdentity @row={{this.vehicleRow}} @column={{this.missingDriverColumn}} />`);

        assert.dom('[data-test-identity-empty-text]').hasText('No driver assigned');
        assert.dom(this.element).doesNotIncludeText('Mercedes 1025');
        assert.dom('.table-cell-resource-identity').doesNotExist();
    });
});
