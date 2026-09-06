import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class MenuServiceStub extends Service {
    getMenuItems() {
        return [];
    }
}

class ResourceContextPanelStub extends Service {
    open(config) {
        this.config = config;
        return config;
    }
}

class FetchStub extends Service {}

class MaintenanceScheduleActionsStub extends Service {
    calls = [];

    modal = {
        create: (...args) => {
            this.calls.push(args);
            return 'opened';
        },
    };
}

module('Unit | Service | vehicle-actions', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:universe/menu-service', MenuServiceStub);
        this.owner.register('service:resource-context-panel', ResourceContextPanelStub);
        this.owner.register('service:fetch', FetchStub);
        this.owner.register('service:maintenance-schedule-actions', MaintenanceScheduleActionsStub);
    });

    test('it exists', function (assert) {
        let service = this.owner.lookup('service:vehicle-actions');
        assert.ok(service);
    });

    test('panel.view resolves promise-like vehicle resources before reading metadata', async function (assert) {
        const service = this.owner.lookup('service:vehicle-actions');
        const vehicle = {
            id: 'vehicle-1',
            name: 'Truck 12',
            meta: { _index_resource: true },
            reload: async () => assert.step('vehicle reloaded'),
        };
        const config = await service.panel.view(Promise.resolve(vehicle));

        assert.strictEqual(config.vehicle, vehicle);
        assert.verifySteps(['vehicle reloaded']);
    });

    test('scheduleMaintenance resolves index vehicles and forwards modal save options', async function (assert) {
        const fullVehicle = { id: 'vehicle-uuid', name: 'Truck 1' };
        const indexVehicle = {
            loadResource: async () => fullVehicle,
        };
        const options = { closeOnSuccess: true };
        const saveOptions = { refresh: false, callback() {} };
        const service = this.owner.lookup('service:vehicle-actions');

        const result = await service.scheduleMaintenance(indexVehicle, options, saveOptions);
        const maintenanceScheduleActions = this.owner.lookup('service:maintenance-schedule-actions');

        assert.strictEqual(result, 'opened');
        assert.deepEqual(maintenanceScheduleActions.calls, [[{ subject: fullVehicle }, options, saveOptions]]);
    });

    test('unassignOrders loads assigned orders, highlights the current job, and posts selected orders', async function (assert) {
        const service = this.owner.lookup('service:vehicle-actions');
        const options = {};
        const posted = {};
        const vehicle = {
            id: 'vehicle-1',
            displayName: 'Truck 12',
            reload: async () => assert.step('vehicle reloaded'),
        };

        service.intl = { t: (key) => key };
        this.owner.lookup('service:host-router').refresh = () => assert.step('refreshed');
        service.notifications = {
            serverError: () => assert.ok(false, 'unexpected call'),
            success: (message) => assert.strictEqual(message, 'vehicle.prompts.unassign-orders-success'),
            warning: () => assert.ok(false, 'unexpected call'),
        };
        service.fetch = {
            get: async (url) => {
                assert.strictEqual(url, 'vehicles/vehicle-1/assigned-orders');

                return {
                    current: 'order-1',
                    orders: [
                        { id: 'order-1', public_id: 'ORD-1', tracking: 'TRK-1' },
                        { id: 'order-2', public_id: 'ORD-2', tracking: 'TRK-2' },
                    ],
                };
            },
            post: async (url, payload) => {
                posted.url = url;
                posted.payload = payload;
            },
        };
        service.modalsManager = {
            show: (_name, modalOptions) => Object.assign(options, modalOptions),
            confirm: (confirmOptions) => confirmOptions.confirm({}, () => {}),
            getOption: (key) => options[key],
            setOption: (key, value) => {
                options[key] = value;
            },
        };

        await service.unassignOrders(vehicle);
        assert.true(options.orders[0].is_current_job, 'marks the active/current job');

        options.toggleOrder(options.orders[0]);
        await options.confirm({
            getOption: (key) => options[key],
            startLoading() {},
            stopLoading() {},
            done: () => assert.step('modal closed'),
        });

        assert.deepEqual(posted, {
            url: 'vehicles/vehicle-1/unassign-orders',
            payload: { orders: ['order-1'] },
        });
        assert.verifySteps(['vehicle reloaded', 'modal closed', 'refreshed']);
    });

    test('unassignOrders warns when no assigned orders are returned', async function (assert) {
        const service = this.owner.lookup('service:vehicle-actions');
        service.intl = { t: (key) => key };
        service.fetch = { get: async () => ({ orders: [] }) };
        service.modalsManager = { show: () => assert.ok(false, 'unexpected call') };
        service.notifications = {
            warning: (message) => assert.strictEqual(message, 'vehicle.prompts.no-assigned-orders-warning'),
            serverError: () => assert.ok(false, 'unexpected call'),
        };

        await service.unassignOrders({ id: 'vehicle-1', displayName: 'Truck 12' });
    });
});
