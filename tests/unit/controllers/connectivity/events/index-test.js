import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class IntlServiceStub extends Service {
    t(key) {
        return key;
    }
}

class DeviceEventActionsStub extends Service {
    transition = { view() {} };
    refresh() {}
    markProcessed() {}
}

class DeviceActionsStub extends Service {
    openedDevice;
    panel = {
        view: (device) => {
            this.openedDevice = device;
        },
    };
}

class HostRouterStub extends Service {
    refresh() {}
}

class StoreStub extends Service {
    peekRecord() {
        return null;
    }

    findRecord() {
        return Promise.resolve({ id: 'device_1', displayName: 'Resolved Device' });
    }
}

class TelematicActionsStub extends Service {
    transition = { view() {} };
}

module('Unit | Controller | connectivity/events/index', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:intl', IntlServiceStub);
        this.owner.register('service:device-event-actions', DeviceEventActionsStub);
        this.owner.register('service:device-actions', DeviceActionsStub);
        this.owner.register('service:host-router', HostRouterStub);
        this.owner.register('service:store', StoreStub);
        this.owner.register('service:telematic-actions', TelematicActionsStub);
    });

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:connectivity/events/index');
        assert.ok(controller);
    });

    test('it exposes backend-supported query params and identity columns', function (assert) {
        let controller = this.owner.lookup('controller:connectivity/events/index');

        assert.deepEqual(controller.queryParams, ['page', 'limit', 'sort', 'query', 'telematic', 'device', 'event_type', 'severity', 'processed', 'occurred_at', 'created_at', 'updated_at']);

        let eventColumn = controller.columns.find((column) => column.label === 'Event');
        let deviceColumn = controller.columns.find((column) => column.label === 'Device');
        let providerColumn = controller.columns.find((column) => column.label === 'Provider');
        let processedColumn = controller.columns.find((column) => column.label === 'Processed');
        let occurredColumn = controller.columns.find((column) => column.label === 'Occurred');

        assert.strictEqual(eventColumn.filterParam, 'event_type');
        assert.strictEqual(deviceColumn.cellComponent, 'cell/device-identity');
        assert.true(deviceColumn.compact);
        assert.strictEqual(deviceColumn.showStatus, false);
        assert.strictEqual(deviceColumn.filterParam, 'device');
        assert.strictEqual(providerColumn.cellComponent, 'cell/telematic-provider');
        assert.true(providerColumn.compact);
        assert.strictEqual(providerColumn.filterParam, 'telematic');
        assert.strictEqual(processedColumn.filterParam, 'processed');
        assert.strictEqual(occurredColumn.filterParam, 'occurred_at');

        assert.ok(
            controller.columns.find((column) => column.label === 'Message'),
            'renders message'
        );
        assert.ok(
            controller.columns.find((column) => column.label === 'Code'),
            'renders code'
        );
        assert.ok(
            controller.columns.find((column) => column.label === 'IDENT'),
            'renders ident'
        );
        assert.ok(
            controller.columns.find((column) => column.label === 'Protocol'),
            'renders protocol'
        );
        assert.ok(
            controller.columns.find((column) => column.label === 'State'),
            'renders state'
        );
    });

    test('it opens event details from the event anchor and dropdown view action', function (assert) {
        let controller = this.owner.lookup('controller:connectivity/events/index');
        let deviceEventActions = this.owner.lookup('service:device-event-actions');
        let eventColumn = controller.columns.find((column) => column.label === 'Event');
        let actionColumn = controller.columns.find((column) => column.cellComponent === 'table/cell/dropdown');
        let viewAction = actionColumn.actions.find((action) => action.permission === 'fleet-ops view device-event');

        assert.strictEqual(eventColumn.action, deviceEventActions.transition.view);
        assert.strictEqual(viewAction.fn, deviceEventActions.transition.view);
    });

    test('it builds event fallback resources and resolves devices before opening panel', async function (assert) {
        let controller = this.owner.lookup('controller:connectivity/events/index');
        let deviceColumn = controller.columns.find((column) => column.label === 'Device');
        let providerColumn = controller.columns.find((column) => column.label === 'Provider');
        let event = {
            device_uuid: 'device_1',
            device_name: 'BX-025',
            ident: '867747078951793',
            provider: 'afaqy',
            provider_descriptor: {
                label: 'AFAQY',
            },
        };

        assert.deepEqual(deviceColumn.resourcePath(event), {
            id: 'device_1',
            displayName: 'BX-025',
            name: 'BX-025',
            imei: '867747078951793',
            device_id: '867747078951793',
            ident: '867747078951793',
            serial_number: undefined,
            connection_status: undefined,
            status: undefined,
            photo_url: undefined,
        });
        assert.deepEqual(providerColumn.resourcePath(event), {
            id: undefined,
            name: 'afaqy',
            provider: 'afaqy',
            provider_descriptor: {
                label: 'AFAQY',
            },
        });

        await controller.openDevice({ id: 'device_1', displayName: 'BX-025' });

        assert.deepEqual(controller.deviceActions.openedDevice, { id: 'device_1', displayName: 'Resolved Device' });
    });
});
