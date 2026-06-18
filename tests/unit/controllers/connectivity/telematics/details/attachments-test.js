import Service from '@ember/service';
import EmberObject from '@ember/object';
import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Controller | connectivity/telematics/details/attachments', function (hooks) {
    setupTest(hooks);

    test('groups attached devices and keeps metrics based on the full loaded collection', function (assert) {
        const controller = this.owner.lookup('controller:connectivity/telematics/details/attachments');

        controller.model = {
            meta: { total: 33, loaded: 33 },
            devices: [
                makeDevice({ id: 'device_1', attachable_uuid: null, name: 'Unattached Alpha', connection_status: 'online', is_online: true }),
                makeDevice({ id: 'device_2', attachable_uuid: 'vehicle_1', attached_to_name: 'Truck 100', name: 'Attached Beta' }),
                makeDevice({ id: 'device_3', attachable_uuid: 'vehicle_1', attached_to_name: 'Truck 100', name: 'Attached Gamma' }),
                makeDevice({ id: 'device_4', attachable_uuid: 'vehicle_2', attached_to_name: 'Truck 200', name: 'Attached Delta' }),
            ],
        };

        assert.strictEqual(controller.totalSyncedDevices, 33, 'total uses route meta');
        assert.strictEqual(controller.attachedDevicesCount, 3, 'attached count uses the full loaded set');
        assert.strictEqual(controller.unattachedDevicesCount, 1, 'unattached count uses the full loaded set');
        assert.strictEqual(controller.mappedVehiclesCount, 2, 'mapped vehicle count is de-duplicated');
        assert.strictEqual(controller.onlineDevicesCount, 1, 'online count uses the full loaded set');
        assert.deepEqual(
            controller.vehicleGroups.map((group) => `${group.name}:${group.devices.length}`),
            ['Truck 100:2', 'Truck 200:1'],
            'attached devices are grouped by vehicle'
        );

        controller.query = 'alpha';

        assert.strictEqual(controller.visibleDevicesCount, 1, 'search filters visible devices');
        assert.strictEqual(controller.unattachedDevices.length, 1, 'search keeps matching unattached devices visible');
        assert.strictEqual(controller.attachedDevicesCount, 3, 'metrics still use full loaded set while filtered');
    });

    test('local attach and detach updates move devices between panes after endpoint success', function (assert) {
        const controller = this.owner.lookup('controller:connectivity/telematics/details/attachments');
        const device = makeDevice({ id: 'device_1', attachable_uuid: null, name: 'AFAQY 1' });
        const vehicle = { id: 'vehicle_1', displayName: 'Truck 100' };

        controller.model = {
            devices: [device],
            meta: { total: 1, loaded: 1 },
        };

        assert.strictEqual(controller.unattachedDevices.length, 1, 'device starts unattached');

        controller.applyDeviceAttachment(device, vehicle);

        assert.strictEqual(controller.unattachedDevices.length, 0, 'attached device leaves unattached pane');
        assert.strictEqual(controller.vehicleGroups.length, 1, 'attached device creates a vehicle group');
        assert.strictEqual(controller.vehicleGroups[0].name, 'Truck 100', 'vehicle group uses selected vehicle display name');

        controller.applyDeviceDetachment(device);

        assert.strictEqual(controller.unattachedDevices.length, 1, 'detached device returns to unattached pane');
        assert.strictEqual(controller.vehicleGroups.length, 0, 'detached device is removed from vehicle groups');
    });

    test('failed attach endpoint keeps the original attachment state', async function (assert) {
        assert.expect(2);
        const selectedVehicle = { id: 'vehicle_1', displayName: 'Truck 100' };

        class FetchStub extends Service {
            post() {
                return Promise.reject(new Error('Attach failed'));
            }
        }

        class ModalStub extends Service {
            show(_name, options) {
                const modal = {
                    getOption(key) {
                        if (key === 'selectedVehicle') {
                            return selectedVehicle;
                        }

                        return options[key];
                    },
                    startLoading() {
                        assert.step('startLoading');
                    },
                    stopLoading() {
                        assert.step('stopLoading');
                    },
                    done() {
                        throw new Error('modal should not complete');
                    },
                };

                return options.confirm(modal);
            }
        }

        class NotificationsStub extends Service {
            serverError() {
                assert.step('serverError');
            }
        }

        this.owner.register('service:fetch', FetchStub);
        this.owner.register('service:modals-manager', ModalStub);
        this.owner.register('service:notifications', NotificationsStub);

        const controller = this.owner.lookup('controller:connectivity/telematics/details/attachments');
        const device = makeDevice({ id: 'device_1', attachable_uuid: null, name: 'AFAQY 1' });

        controller.openAttachDeviceModal(device);
        await settledPromise();

        assert.strictEqual(device.attachable_uuid, null, 'device remains unattached after failed mutation');
        assert.verifySteps(['startLoading', 'serverError', 'stopLoading']);
    });
});

function makeDevice(attributes = {}) {
    return EmberObject.create({
        id: attributes.id,
        displayName: attributes.displayName ?? attributes.name,
        name: attributes.name,
        device_id: attributes.device_id ?? attributes.id,
        serial_number: attributes.serial_number,
        imei: attributes.imei,
        status: attributes.status,
        connection_status: attributes.connection_status,
        is_online: attributes.is_online ?? false,
        attachable_uuid: attributes.attachable_uuid,
        attachable_type: attributes.attachable_type,
        attached_to_name: attributes.attached_to_name,
        attachable: attributes.attachable,
    });
}

function settledPromise() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}
