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

    test('selecting an unattached device marks and toggles the selected device', function (assert) {
        const controller = this.owner.lookup('controller:connectivity/telematics/details/attachments');
        const device = makeDevice({ id: 'device_1', attachable_uuid: null, name: 'AFAQY 1' });

        controller.selectUnattachedDevice(device);

        assert.strictEqual(controller.selectedDevice, device, 'device is selected');
        assert.strictEqual(controller.selectedDeviceName, 'AFAQY 1', 'selected device name is exposed for pane instructions');

        controller.selectUnattachedDevice(device);

        assert.strictEqual(controller.selectedDevice, null, 'clicking selected device clears selection');
    });

    test('vehicle filter stores the selected vehicle model and uuid', function (assert) {
        const controller = this.owner.lookup('controller:connectivity/telematics/details/attachments');
        const vehicle = { id: 'vehicle_1', displayName: 'Truck 100' };

        controller.updateSelectedVehicle(vehicle);

        assert.strictEqual(controller.selectedVehicle, vehicle, 'selected vehicle model is retained for the select trigger');
        assert.strictEqual(controller.vehicle, 'vehicle_1', 'vehicle query param stores the selected vehicle id');

        controller.updateSelectedVehicle(null);

        assert.strictEqual(controller.selectedVehicle, null, 'clearing the select clears the selected model');
        assert.strictEqual(controller.vehicle, null, 'clearing the select clears the query param id');
    });

    test('vehicle filter only filters attached vehicle groups', function (assert) {
        const controller = this.owner.lookup('controller:connectivity/telematics/details/attachments');

        controller.model = {
            meta: { total: 3, loaded: 3 },
            devices: [
                makeDevice({ id: 'device_1', attachable_uuid: null, name: 'Unattached Alpha' }),
                makeDevice({ id: 'device_2', attachable_uuid: 'vehicle_1', attached_to_name: 'Truck 100', name: 'Attached Beta' }),
                makeDevice({ id: 'device_3', attachable_uuid: 'vehicle_2', attached_to_name: 'Truck 200', name: 'Attached Gamma' }),
            ],
        };

        controller.vehicle = 'vehicle_2';

        assert.strictEqual(controller.unattachedDevices.length, 1, 'vehicle filter does not remove unattached devices');
        assert.deepEqual(
            controller.vehicleGroups.map((group) => group.name),
            ['Truck 200'],
            'vehicle filter narrows attached vehicle groups'
        );
        assert.strictEqual(controller.visibleDevicesCount, 2, 'visible count includes left pane plus the filtered right pane');
    });

    test('filtered empty only applies when both panes have no visible rows', function (assert) {
        const controller = this.owner.lookup('controller:connectivity/telematics/details/attachments');

        controller.model = {
            meta: { total: 2, loaded: 2 },
            devices: [
                makeDevice({ id: 'device_1', attachable_uuid: null, name: 'Unattached Alpha' }),
                makeDevice({ id: 'device_2', attachable_uuid: 'vehicle_1', attached_to_name: 'Truck 100', name: 'Attached Beta' }),
            ],
        };

        controller.vehicle = 'vehicle_2';

        assert.strictEqual(controller.emptyStateVariant, null, 'vehicle-only misses do not replace the workspace while unattached rows remain visible');

        controller.query = 'missing-device';

        assert.strictEqual(controller.emptyStateVariant, 'filtered_empty', 'filtered empty appears when both panes have no visible rows');
    });

    test('search filters both panes without changing full metrics', function (assert) {
        const controller = this.owner.lookup('controller:connectivity/telematics/details/attachments');

        controller.model = {
            meta: { total: 3, loaded: 3 },
            devices: [
                makeDevice({ id: 'device_1', attachable_uuid: null, name: 'Alpha Device' }),
                makeDevice({ id: 'device_2', attachable_uuid: 'vehicle_1', attached_to_name: 'Alpha Truck', name: 'Mounted Device' }),
                makeDevice({ id: 'device_3', attachable_uuid: 'vehicle_2', attached_to_name: 'Beta Truck', name: 'Other Device' }),
            ],
        };

        controller.query = 'alpha';

        assert.strictEqual(controller.unattachedDevices.length, 1, 'search filters matching unattached devices');
        assert.deepEqual(
            controller.vehicleGroups.map((group) => group.name),
            ['Alpha Truck'],
            'search filters matching attached vehicle groups'
        );
        assert.strictEqual(controller.attachedDevicesCount, 2, 'full attached metrics are not filtered');
        assert.strictEqual(controller.unattachedDevicesCount, 1, 'full unattached metrics are not filtered');
    });

    test('status filters both panes', function (assert) {
        const controller = this.owner.lookup('controller:connectivity/telematics/details/attachments');

        controller.model = {
            meta: { total: 4, loaded: 4 },
            devices: [
                makeDevice({ id: 'device_1', attachable_uuid: null, name: 'Unattached Online', connection_status: 'online' }),
                makeDevice({ id: 'device_2', attachable_uuid: null, name: 'Unattached Offline', connection_status: 'offline' }),
                makeDevice({ id: 'device_3', attachable_uuid: 'vehicle_1', attached_to_name: 'Online Truck', name: 'Attached Online', connection_status: 'online' }),
                makeDevice({ id: 'device_4', attachable_uuid: 'vehicle_2', attached_to_name: 'Offline Truck', name: 'Attached Offline', connection_status: 'offline' }),
            ],
        };

        controller.status = 'online';

        assert.deepEqual(
            controller.unattachedDevices.map((device) => device.name),
            ['Unattached Online'],
            'status filters unattached devices'
        );
        assert.deepEqual(
            controller.vehicleGroups.map((group) => group.name),
            ['Online Truck'],
            'status filters attached vehicle groups'
        );
    });

    test('clearing filters clears the selected vehicle model and uuid', function (assert) {
        const controller = this.owner.lookup('controller:connectivity/telematics/details/attachments');
        const vehicle = { id: 'vehicle_1', displayName: 'Truck 100' };

        controller.query = 'truck';
        controller.status = 'online';
        controller.vehicle = vehicle.id;
        controller.selectedVehicle = vehicle;

        controller.clearFilters();

        assert.strictEqual(controller.query, null, 'query is cleared');
        assert.strictEqual(controller.status, null, 'status is cleared');
        assert.strictEqual(controller.vehicle, null, 'vehicle query param is cleared');
        assert.strictEqual(controller.selectedVehicle, null, 'selected vehicle model is cleared');
    });

    test('refresh toggles loading state and resets after success', async function (assert) {
        assert.expect(3);

        class HostRouterStub extends Service {
            refresh() {
                assert.true(controller.isRefreshing, 'refresh starts loading before calling the router');

                return Promise.resolve('refreshed');
            }
        }

        this.owner.register('service:host-router', HostRouterStub);

        const controller = this.owner.lookup('controller:connectivity/telematics/details/attachments');

        assert.false(controller.isRefreshing, 'refresh starts idle');

        await controller.refresh();

        assert.false(controller.isRefreshing, 'refresh resets loading after success');
    });

    test('refresh resets loading state after failure', async function (assert) {
        assert.expect(3);

        class HostRouterStub extends Service {
            refresh() {
                assert.true(controller.isRefreshing, 'refresh starts loading before calling the router');

                return Promise.reject(new Error('Refresh failed'));
            }
        }

        this.owner.register('service:host-router', HostRouterStub);

        const controller = this.owner.lookup('controller:connectivity/telematics/details/attachments');

        assert.false(controller.isRefreshing, 'refresh starts idle');

        try {
            await controller.refresh();
        } catch (error) {
            assert.false(controller.isRefreshing, 'refresh resets loading after failure');
        }
    });

    test('attaching a selected device to a vehicle group moves it and clears selection', async function (assert) {
        class FetchStub extends Service {
            post(url, payload) {
                assert.strictEqual(url, 'devices/device_1/attach', 'attach endpoint is used');
                assert.deepEqual(payload, { vehicle: 'vehicle_1' }, 'vehicle target is sent');

                return Promise.resolve({ device: { attachable_uuid: 'vehicle_1', attached_to_name: 'Truck 100' } });
            }
        }

        class NotificationsStub extends Service {
            success() {
                assert.step('success');
            }
        }

        this.owner.register('service:fetch', FetchStub);
        this.owner.register('service:notifications', NotificationsStub);

        const controller = this.owner.lookup('controller:connectivity/telematics/details/attachments');
        const device = makeDevice({ id: 'device_1', attachable_uuid: null, name: 'AFAQY 1' });

        controller.model = {
            devices: [device],
            meta: { total: 1, loaded: 1 },
        };
        controller.selectedDevice = device;

        await controller.attachSelectedDeviceToGroup({ id: 'vehicle_1', name: 'Truck 100' });

        assert.strictEqual(controller.selectedDevice, null, 'selection is cleared after successful attach');
        assert.strictEqual(controller.unattachedDevices.length, 0, 'device leaves unattached pane');
        assert.strictEqual(controller.vehicleGroups.length, 1, 'device appears in attached vehicle groups');
        assert.verifySteps(['success']);
    });

    test('failed attach endpoint keeps the original attachment state', async function (assert) {
        assert.expect(3);
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

        controller.selectedDevice = device;
        controller.openAttachDeviceModal(device);
        await settledPromise();

        assert.strictEqual(device.attachable_uuid, null, 'device remains unattached after failed mutation');
        assert.strictEqual(controller.selectedDevice, device, 'selected device remains selected after failed mutation');
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
        public_id: attributes.public_id,
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
