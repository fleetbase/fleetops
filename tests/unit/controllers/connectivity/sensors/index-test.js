import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class IntlServiceStub extends Service {
    t(key) {
        return key;
    }
}

class SensorActionsStub extends Service {
    transition = { view() {}, edit() {}, create() {} };
    refresh() {}
    import() {}
    export() {}
    bulkDelete() {}
    delete() {}
}

class DeviceActionsStub extends Service {
    openedDevice;
    panel = {
        view: (device) => {
            this.openedDevice = device;
        },
    };
}

class StoreStub extends Service {
    peekRecord() {
        return { id: 'device_1', displayName: 'Cached Device' };
    }

    findRecord() {
        throw new Error('findRecord should not run when cached');
    }
}

class TelematicActionsStub extends Service {
    transition = { view() {} };
}

module('Unit | Controller | connectivity/sensors/index', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:intl', IntlServiceStub);
        this.owner.register('service:sensor-actions', SensorActionsStub);
        this.owner.register('service:device-actions', DeviceActionsStub);
        this.owner.register('service:store', StoreStub);
        this.owner.register('service:telematic-actions', TelematicActionsStub);
    });

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:connectivity/sensors/index');
        assert.ok(controller);
    });

    test('it exposes backend-supported query params and identity columns', function (assert) {
        let controller = this.owner.lookup('controller:connectivity/sensors/index');

        assert.deepEqual(controller.queryParams, [
            'page',
            'limit',
            'sort',
            'query',
            'telematic',
            'device',
            'type',
            'status',
            'serial_number',
            'imei',
            'last_reading_at',
            'created_at',
            'updated_at',
        ]);

        let telematicColumn = controller.columns.find((column) => column.label === 'Telematic');
        let deviceColumn = controller.columns.find((column) => column.label === 'Device');
        let typeColumn = controller.columns.find((column) => column.label === 'Type');
        let serialColumn = controller.columns.find((column) => column.label === 'Serial Number');
        let imeiColumn = controller.columns.find((column) => column.label === 'IMEI');
        let lastReadingColumn = controller.columns.find((column) => column.label === 'Last Reading');

        assert.strictEqual(telematicColumn.cellComponent, 'cell/telematic-provider');
        assert.true(telematicColumn.compact);
        assert.strictEqual(telematicColumn.filterParam, 'telematic');
        assert.strictEqual(deviceColumn.cellComponent, 'cell/device-identity');
        assert.strictEqual(deviceColumn.filterParam, 'device');
        assert.strictEqual(typeColumn.cellComponent, 'table/cell/base');
        assert.true(typeColumn.humanize);
        assert.strictEqual(serialColumn.filterParam, 'serial_number');
        assert.strictEqual(imeiColumn.filterParam, 'imei');
        assert.strictEqual(lastReadingColumn.filterParam, 'last_reading_at');

        assert.ok(
            controller.columns.find((column) => column.label === 'Last Value'),
            'renders last value'
        );
        assert.ok(
            controller.columns.find((column) => column.label === 'Unit'),
            'renders unit'
        );
        assert.ok(
            controller.columns.find((column) => column.label === 'Threshold'),
            'renders threshold status'
        );
    });

    test('it builds sensor fallback resources and opens cached device panel', async function (assert) {
        let controller = this.owner.lookup('controller:connectivity/sensors/index');
        let telematicColumn = controller.columns.find((column) => column.label === 'Telematic');
        let deviceColumn = controller.columns.find((column) => column.label === 'Device');
        let sensor = {
            device_uuid: 'device_1',
            device_name: 'BX-025',
            ident: '867747078951793',
            provider: 'afaqy',
            provider_descriptor: {
                label: 'AFAQY',
            },
        };

        assert.strictEqual(deviceColumn.resourcePath(sensor).imei, '867747078951793');
        assert.strictEqual(deviceColumn.resourcePath(sensor).device_id, '867747078951793');
        assert.deepEqual(telematicColumn.resourcePath(sensor).provider_descriptor, { label: 'AFAQY' });

        await controller.openDevice({ id: 'device_1', displayName: 'BX-025' });

        assert.deepEqual(controller.deviceActions.openedDevice, { id: 'device_1', displayName: 'Cached Device' });
    });
});
