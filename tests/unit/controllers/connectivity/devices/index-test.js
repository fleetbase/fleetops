import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class DeviceActionsStub extends Service {
    panel = {
        view() {},
        edit() {},
    };

    refresh() {}
    import() {}
    export() {}
    attachToVehicle() {}
    detachFromVehicle() {}
    delete() {}

    transition = {
        create() {},
        view() {},
        edit() {},
    };
}

class TelematicActionsStub extends Service {
    transition = {
        view() {},
    };
}

class VehicleActionsStub extends Service {
    panel = {
        view() {},
    };
}

module('Unit | Controller | connectivity/devices/index', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:connectivity/devices/index');
        assert.ok(controller);
    });

    test('query params expose device inventory filters', function (assert) {
        const controller = this.owner.lookup('controller:connectivity/devices/index');

        assert.deepEqual(
            controller.queryParams.filter((param) =>
                ['connection_status', 'vehicle', 'provider', 'device_id', 'type', 'serial_number', 'last_online_at', 'attachment_state'].includes(param)
            ),
            ['attachment_state', 'provider', 'vehicle', 'connection_status', 'device_id', 'type', 'serial_number', 'last_online_at'],
            'device inventory query params include provider, vehicle, connection, identity, type, serial, last seen, and attachment filters'
        );
    });

    test('columns expose telematic device, provider, vehicle, sensors, and connection contracts', function (assert) {
        this.owner.register('service:device-actions', DeviceActionsStub);
        this.owner.register('service:telematic-actions', TelematicActionsStub);
        this.owner.register('service:vehicle-actions', VehicleActionsStub);

        const controller = this.owner.lookup('controller:connectivity/devices/index');
        const deviceColumn = controller.columns.find((column) => column.label === 'Telematic Device');
        const providerColumn = controller.columns.find((column) => column.label === 'Telematic Provider');
        const vehicleColumn = controller.columns.find((column) => column.label === 'Vehicle');
        const sensorColumn = controller.columns.find((column) => column.label === 'Sensors');
        const connectionColumn = controller.columns.find((column) => column.label === 'Connection');
        const actionsColumn = controller.columns.find((column) => column.cellComponent === 'table/cell/dropdown');
        const [viewAction, editAction] = actionsColumn.actions;
        const visibleColumnOrder = controller.columns.filter((column) => !column.hidden).map((column) => column.label);

        assert.strictEqual(deviceColumn.cellComponent, 'cell/device-identity', 'device identity uses shared identity wrapper');
        assert.strictEqual(deviceColumn.showStatus, false, 'device identity suppresses duplicate connection status in this index');
        assert.strictEqual(deviceColumn.action, controller.deviceActions.transition.view, 'global device identity transitions to the device details route');
        assert.deepEqual(
            visibleColumnOrder.slice(0, 4),
            ['Telematic Device', 'Connection', 'Telematic Provider', 'Vehicle'],
            'visible columns place connection after device and vehicle after provider'
        );
        assert.strictEqual(providerColumn.cellComponent, 'cell/telematic-provider', 'provider uses provider cell');
        assert.true(providerColumn.compact, 'provider cell uses compact index layout');
        assert.strictEqual(providerColumn.filterParam, 'telematic', 'provider connection filter remains model-backed');
        assert.strictEqual(vehicleColumn.cellComponent, 'cell/vehicle-identity', 'vehicle uses shared vehicle identity cell');
        assert.strictEqual(vehicleColumn.filterParam, 'vehicle', 'vehicle filter maps to vehicle query param');
        assert.strictEqual(vehicleColumn.showStatusBadge, true, 'attached vehicle column renders vehicle status as a compact badge');
        assert.strictEqual(
            vehicleColumn.resourcePath({
                attachable_uuid: 'vehicle_1',
                attached_to_name: 'Truck 1',
            }).vehicle_number,
            'vehicle_1',
            'fallback attached vehicle resource provides badge metadata'
        );
        assert.strictEqual(sensorColumn.valuePath, 'sensors_count', 'sensor count column reads backend count');
        assert.false(sensorColumn.sortable, 'sensor count is display-only to avoid fragile aggregate sorting');
        assert.strictEqual(connectionColumn.filterParam, 'connection_status', 'connection filter maps to backend connection status');
        assert.deepEqual(
            connectionColumn.filterOptions.map((option) => option.value),
            ['online', 'recently_offline', 'offline', 'long_offline', 'never_connected'],
            'connection filter options match backend status buckets'
        );
        assert.strictEqual(viewAction.fn, controller.deviceActions.transition.view, 'global dropdown view transitions to the device details route');
        assert.strictEqual(editAction.fn, controller.deviceActions.transition.edit, 'global dropdown edit transitions to the device edit route');
    });
});
