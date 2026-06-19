import { module, test } from 'qunit';
import EmberObject from '@ember/object';
import ObjectProxy from '@ember/object/proxy';
import { buildTrackableOption, trackableDeviceLabel } from '@fleetbase/fleetops-engine/utils/trackable-option';

module('Unit | Utility | trackable-option', function () {
    test('builds searchable text for vehicle trackables including attached devices', function (assert) {
        const option = buildTrackableOption(
            {
                constructor: { modelName: 'vehicle' },
                id: 'vehicle_1',
                name: 'Box Truck',
                public_id: 'vehicle_public',
                plate_number: 'ABC-123',
                serial_number: 'SER-9',
                devices: [{ name: 'Dashcam 1' }, { device_id: 'OBD-2' }],
            },
            'vehicle'
        );

        assert.strictEqual(option.primaryLabel, 'Box Truck');
        assert.strictEqual(option.secondaryLabel, 'ABC-123');
        assert.strictEqual(option.deviceLabel, 'Devices: Dashcam 1, OBD-2');
        assert.true(option.trackableSearchText.includes('ABC-123'), 'vehicle identifiers are searchable');
        assert.true(option.trackableSearchText.includes('Dashcam 1'), 'attached device names are searchable');
    });

    test('builds searchable text for driver trackables using assigned vehicle devices', function (assert) {
        const option = buildTrackableOption(
            {
                constructor: { modelName: 'driver' },
                id: 'driver_1',
                name: 'Mira Driver',
                email: 'mira@example.test',
                vehicle: {
                    devices: [{ serial_number: 'SN-7' }],
                },
            },
            'driver'
        );

        assert.strictEqual(option.primaryLabel, 'Mira Driver');
        assert.strictEqual(option.secondaryLabel, 'mira@example.test');
        assert.strictEqual(option.deviceLabel, 'Device: SN-7');
        assert.true(option.trackableSearchText.includes('SN-7'), 'assigned vehicle device identifiers are searchable');
    });

    test('omits device labels when no attached devices exist', function (assert) {
        assert.strictEqual(trackableDeviceLabel({ constructor: { modelName: 'vehicle' }, devices: [] }, 'vehicle'), null);
        assert.strictEqual(trackableDeviceLabel({ constructor: { modelName: 'driver' }, vehicle: null }, 'driver'), null);
    });

    test('reads devices from Ember proxy records', function (assert) {
        const driver = ObjectProxy.create({
            content: EmberObject.create({
                name: 'Proxy Driver',
                email: 'proxy@example.test',
                vehicle: EmberObject.create({
                    devices: [EmberObject.create({ name: 'Proxy Tracker' })],
                }),
            }),
        });

        const option = buildTrackableOption(driver, 'driver');

        assert.strictEqual(option.deviceLabel, 'Device: Proxy Tracker');
        assert.true(option.trackableSearchText.includes('Proxy Tracker'), 'proxy device name is searchable');
    });
});
