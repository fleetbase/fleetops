import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

class IntlStub extends Service {
    t(key) {
        return key;
    }
}

class TableContextStub extends Service {
    getSelectedRows() {
        return [];
    }
}

class AppCacheStub extends Service {
    get(_key, fallback) {
        return fallback;
    }

    set() {}
}

class DriverActionsStub extends Service {
    transition = {
        view() {},
        create() {},
        edit() {},
    };

    panel = {
        view(resource) {
            this.viewed = resource;
        },
    };
}

class VehicleActionsStub extends Service {
    transition = {
        view() {},
        create() {},
        edit() {},
    };

    panel = {
        view(resource) {
            this.viewed = resource;
        },
    };
}

class GenericActionsStub extends Service {
    transition = {
        view() {},
        create() {},
        edit() {},
    };

    panel = {
        view() {},
    };
}

class RelatedResourceActionsStub extends GenericActionsStub {
    driverActions = {
        panel: {
            view(resource) {
                this.viewed = resource;
            },
        },
    };

    vehicleActions = {
        panel: {
            view(resource) {
                this.viewed = resource;
            },
        },
    };
}

module('Unit | Controller | management identity columns', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:intl', IntlStub);
        this.owner.register('service:table-context', TableContextStub);
        this.owner.register('service:app-cache', AppCacheStub);
        this.owner.register('service:driver-actions', DriverActionsStub);
        this.owner.register('service:vehicle-actions', VehicleActionsStub);
        this.owner.register('service:fleet-actions', GenericActionsStub);
        this.owner.register('service:vendor-actions', GenericActionsStub);
        this.owner.register('service:issue-actions', RelatedResourceActionsStub);
        this.owner.register('service:fuel-report-actions', RelatedResourceActionsStub);
        this.owner.register('service:notifications', GenericActionsStub);
    });

    test('drivers columns place phone, license, and vehicle after ID', async function (assert) {
        const controller = this.owner.lookup('controller:management/drivers/index');
        const labels = controller.columns.slice(0, 5).map((column) => column.label);
        const vehicleColumn = controller.columns.find((column) => column.label === 'column.vehicle');
        const vehicle = { id: 'vehicle_1', displayName: 'Truck 1' };

        assert.deepEqual(labels, ['column.name', 'column.id', 'column.phone', 'column.license', 'column.vehicle']);
        assert.strictEqual(controller.columns[0].cellComponent, 'cell/driver-identity');
        assert.true(controller.columns[0].compact);
        assert.strictEqual(vehicleColumn.cellComponent, 'cell/vehicle-identity');
        assert.true(vehicleColumn.compact);
        assert.strictEqual(vehicleColumn.showStatusBadge, true);

        await vehicleColumn.action({ loadResource: () => vehicle });

        assert.strictEqual(controller.vehicleActions.panel.viewed, vehicle, 'vehicle column opens the vehicle panel with the resolved vehicle');

        await vehicleColumn.action(Promise.resolve(vehicle));

        assert.strictEqual(controller.vehicleActions.panel.viewed, vehicle, 'vehicle column resolves async belongsTo-style vehicle values before opening the panel');
    });

    test('vehicles driver column uses driver identity and opens the driver panel', async function (assert) {
        const controller = this.owner.lookup('controller:management/vehicles/index');
        const driverColumn = controller.columns.find((column) => column.label === 'column.driver-assigned');
        const driver = { id: 'driver_1', name: 'Ada Driver' };
        const vehicle = { displayName: 'Truck 1' };

        assert.strictEqual(controller.columns[0].cellComponent, 'cell/vehicle-identity');
        assert.strictEqual(controller.columns[0].showStatus, false);
        assert.strictEqual(driverColumn.cellComponent, 'cell/driver-identity');
        assert.true(driverColumn.compact);
        assert.strictEqual(driverColumn.assignedVehicleLabel(driver, vehicle), 'Truck 1');

        await driverColumn.action({ loadResource: () => driver });

        assert.strictEqual(controller.driverActions.panel.viewed, driver, 'driver column opens the driver panel with the resolved driver');
    });

    test('fuel reports driver and vehicle columns use identity cells and preserve panel actions', async function (assert) {
        const controller = this.owner.lookup('controller:management/fuel-reports/index');
        const driverColumn = controller.columns.find((column) => column.label === 'column.driver');
        const vehicleColumn = controller.columns.find((column) => column.label === 'column.vehicle');
        const driver = { id: 'driver_1', name: 'Ada Driver' };
        const vehicle = { id: 'vehicle_1', displayName: 'Truck 1' };

        assert.strictEqual(driverColumn.cellComponent, 'cell/driver-identity');
        assert.strictEqual(vehicleColumn.cellComponent, 'cell/vehicle-identity');
        assert.strictEqual(driverColumn.showStatusBadge, true);
        assert.strictEqual(vehicleColumn.showStatusBadge, true);

        await driverColumn.action({ loadResource: () => driver });
        await vehicleColumn.action({ loadResource: () => vehicle });

        assert.strictEqual(controller.fuelReportActions.driverActions.panel.viewed, driver, 'fuel report driver column opens driver panel');
        assert.strictEqual(controller.fuelReportActions.vehicleActions.panel.viewed, vehicle, 'fuel report vehicle column opens vehicle panel');
    });

    test('issues driver and vehicle columns use identity cells and preserve panel actions', async function (assert) {
        const controller = this.owner.lookup('controller:management/issues/index');
        const driverColumn = controller.columns.find((column) => column.label === 'column.driver');
        const vehicleColumn = controller.columns.find((column) => column.label === 'column.vehicle');
        const driver = { id: 'driver_1', name: 'Ada Driver' };
        const vehicle = { id: 'vehicle_1', displayName: 'Truck 1' };

        assert.strictEqual(driverColumn.cellComponent, 'cell/driver-identity');
        assert.strictEqual(vehicleColumn.cellComponent, 'cell/vehicle-identity');
        assert.strictEqual(driverColumn.showStatusBadge, true);
        assert.strictEqual(vehicleColumn.showStatusBadge, true);

        await driverColumn.action({ loadResource: () => driver });
        await vehicleColumn.action({ loadResource: () => vehicle });

        assert.strictEqual(controller.issueActions.driverActions.panel.viewed, driver, 'issue driver column opens driver panel');
        assert.strictEqual(controller.issueActions.vehicleActions.panel.viewed, vehicle, 'issue vehicle column opens vehicle panel');
    });
});
