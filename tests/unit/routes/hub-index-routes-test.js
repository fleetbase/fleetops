import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class FetchStubService extends Service {
    get() {
        return Promise.resolve({ actions: [] });
    }
}

class DocsPanelStubService extends Service {
    open() {}
}

module('Unit | Route | fleet-ops hub index routes', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:docs-panel', DocsPanelStubService);
    });

    test('it registers the FleetOps hub route templates', function (assert) {
        assert.ok(this.owner.resolveRegistration('template:management/index'), 'resources hub template is registered');
        assert.ok(this.owner.resolveRegistration('controller:management/index'), 'resources hub controller is registered');
        assert.ok(this.owner.resolveRegistration('route:maintenance/index'), 'maintenance hub route is registered');
        assert.ok(this.owner.resolveRegistration('controller:maintenance/index'), 'maintenance hub controller is registered');
        assert.ok(this.owner.resolveRegistration('template:maintenance/index'), 'maintenance hub template is registered');
        assert.ok(this.owner.resolveRegistration('route:analytics/index'), 'analytics hub route is registered');
        assert.ok(this.owner.resolveRegistration('template:analytics/index'), 'analytics hub template is registered');
        assert.ok(this.owner.resolveRegistration('route:settings/index'), 'settings hub route is registered');
        assert.ok(this.owner.resolveRegistration('controller:settings/index'), 'settings hub controller is registered');
        assert.ok(this.owner.resolveRegistration('template:settings/index'), 'settings hub template is registered');
    });

    test('resource action target routes expose hub query params', function (assert) {
        const devicesRoute = this.owner.lookup('route:connectivity/devices/index');
        const devicesController = this.owner.lookup('controller:connectivity/devices/index');
        const workOrdersRoute = this.owner.lookup('route:maintenance/work-orders/index');

        assert.deepEqual(devicesRoute.queryParams.status, { refreshModel: true }, 'devices route refreshes status filters');
        assert.deepEqual(devicesRoute.queryParams.attachment_state, { refreshModel: true }, 'devices route refreshes attachment state filters');
        assert.deepEqual(devicesRoute.queryParams.telematic, { refreshModel: true }, 'devices route refreshes telematic filters');
        assert.true(devicesController.queryParams.includes('attachment_state'), 'devices controller keeps attachment state in URL state');
        assert.true(devicesController.queryParams.includes('telematic'), 'devices controller keeps telematic in URL state');
        assert.deepEqual(workOrdersRoute.queryParams.status, { refreshModel: true }, 'work orders route refreshes status filters');
        assert.deepEqual(workOrdersRoute.queryParams.priority, { refreshModel: true }, 'work orders route refreshes priority filters');
    });

    test('hub controllers normalize action query values for LinkTo', function (assert) {
        const managementController = this.owner.lookup('controller:management/index');
        const maintenanceController = this.owner.lookup('controller:maintenance/index');

        managementController.hub = {
            actions: [
                { key: 'missing-query', route: 'management.drivers' },
                { key: 'array-query', route: 'management.vehicles', query: [] },
                { key: 'object-query', route: 'management.issues', query: { status: 'open' } },
            ],
        };
        maintenanceController.hub = {
            actions: [
                { key: 'null-query', route: 'maintenance.schedules', query: null },
                { key: 'object-query', route: 'maintenance.work-orders', query: { status: 'open' } },
            ],
        };

        assert.deepEqual(managementController.actions[0].query, {}, 'missing resource action query becomes an object');
        assert.deepEqual(managementController.actions[1].query, {}, 'array resource action query becomes an object');
        assert.deepEqual(managementController.actions[2].query, { status: 'open' }, 'object resource action query is preserved');
        assert.deepEqual(maintenanceController.actions[0].query, {}, 'null maintenance action query becomes an object');
        assert.deepEqual(maintenanceController.actions[1].query, { status: 'open' }, 'object maintenance action query is preserved');
    });
});
