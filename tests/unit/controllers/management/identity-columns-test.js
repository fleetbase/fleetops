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
        const vendorColumn = controller.columns.find((column) => column.label === 'column.vendor');
        const vehicle = { id: 'vehicle_1', displayName: 'Truck 1' };

        assert.deepEqual(labels, ['column.name', 'column.id', 'column.phone', 'column.license', 'column.vehicle']);
        assert.strictEqual(controller.columns[0].cellComponent, 'cell/driver-identity');
        assert.strictEqual(controller.columns[0].compact, undefined, 'the one-line cell needs no compact flag');
        assert.strictEqual(vehicleColumn.cellComponent, 'cell/vehicle-identity');
        assert.strictEqual(vehicleColumn.showStatusBadge, undefined, 'there is no status badge any more');
        assert.strictEqual(vendorColumn.cellComponent, 'cell/vendor-identity');
        assert.strictEqual(vendorColumn.valuePath, 'vendor_name', 'vendor renders from the accessor returned by the driver list endpoint');

        await vehicleColumn.action({ loadResource: () => vehicle });

        assert.strictEqual(controller.vehicleActions.panel.viewed, vehicle, 'vehicle column opens the vehicle panel with the resolved vehicle');

        await vehicleColumn.action(Promise.resolve(vehicle));

        assert.strictEqual(controller.vehicleActions.panel.viewed, vehicle, 'vehicle column resolves async belongsTo-style vehicle values before opening the panel');
    });

    test('rows that only carry a name get an identity stub without a UUID identifier', function (assert) {
        const controller = this.owner.lookup('controller:management/drivers/index');
        const vehicleColumn = controller.columns.find((column) => column.label === 'column.vehicle');
        const vendorColumn = controller.columns.find((column) => column.label === 'column.vendor');
        const driver = { vehicle_name: 'Truck 1', vehicle_uuid: '9d2c6c5e-1b2a-4c3d-8e4f-1234567890ab', vendor_name: 'Acme', vendor_uuid: '9d2c6c5e-1b2a-4c3d-8e4f-1234567890ac' };

        const vehicleStub = vehicleColumn.resourcePath(driver);
        const vendorStub = vendorColumn.resourcePath(driver);

        assert.strictEqual(vehicleStub.name, 'Truck 1');
        assert.strictEqual(vehicleStub.resourceType, 'vehicle');
        assert.strictEqual(typeof vehicleStub.loadResource, 'function');
        assert.strictEqual(vendorStub.resourceType, 'vendor');

        for (const stub of [vehicleStub, vendorStub]) {
            for (const [field, value] of Object.entries(stub)) {
                assert.notOk(typeof value === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-/i.test(value), `${field} is not a UUID`);
            }
        }

        assert.strictEqual(vehicleColumn.resourcePath({}), null, 'no name, no stub');
    });

    test('vehicles driver column uses driver identity and opens the driver panel', async function (assert) {
        const controller = this.owner.lookup('controller:management/vehicles/index');
        const driverColumn = controller.columns.find((column) => column.label === 'column.driver-assigned');
        const vendorColumn = controller.columns.find((column) => column.label === 'column.vendor');
        const driver = { id: 'driver_1', name: 'Ada Driver' };

        assert.strictEqual(controller.columns[0].cellComponent, 'cell/vehicle-identity');
        assert.strictEqual(controller.columns[0].showStatus, undefined);
        assert.strictEqual(driverColumn.cellComponent, 'cell/driver-identity');
        assert.strictEqual(driverColumn.compact, undefined);
        assert.strictEqual(driverColumn.assignedVehicleLabel, undefined, 'the descriptor supplies the badge');
        assert.strictEqual(driverColumn.filterComponent, 'filter/model-multiple');
        assert.strictEqual(vendorColumn.cellComponent, 'cell/vendor-identity');
        assert.strictEqual(vendorColumn.action, controller.vendorActions.panel.view, 'the vendor column opens the panel rather than a viewVendor that never existed');

        await driverColumn.action(driver);

        assert.strictEqual(controller.driverActions.panel.viewed, driver, 'driver column opens the driver panel');
        assert.strictEqual(driverColumn.resourcePath({ driver_name: 'Ada' }).resourceType, 'driver', 'a stub is built from driver_name');
    });

    test('fuel reports driver and vehicle columns use identity cells and preserve panel actions', async function (assert) {
        const controller = this.owner.lookup('controller:management/fuel-reports/index');
        const driverColumn = controller.columns.find((column) => column.label === 'column.driver');
        const vehicleColumn = controller.columns.find((column) => column.label === 'column.vehicle');
        const reporterColumn = controller.columns.find((column) => column.label === 'column.reporter');
        const driver = { id: 'driver_1', name: 'Ada Driver' };
        const vehicle = { id: 'vehicle_1', displayName: 'Truck 1' };

        assert.strictEqual(driverColumn.cellComponent, 'cell/driver-identity');
        assert.strictEqual(vehicleColumn.cellComponent, 'cell/vehicle-identity');
        assert.strictEqual(reporterColumn.cellComponent, 'table/cell/user-identity');
        assert.strictEqual(driverColumn.showStatusBadge, undefined);
        assert.strictEqual(vehicleColumn.showStatusBadge, undefined);

        await driverColumn.action(driver);
        await vehicleColumn.action(vehicle);

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
        assert.strictEqual(driverColumn.showStatusBadge, undefined);
        assert.strictEqual(vehicleColumn.showStatusBadge, undefined);

        await driverColumn.action(driver);
        await vehicleColumn.action(vehicle);

        assert.strictEqual(controller.issueActions.driverActions.panel.viewed, driver, 'issue driver column opens driver panel');
        assert.strictEqual(controller.issueActions.vehicleActions.panel.viewed, vehicle, 'issue vehicle column opens vehicle panel');
    });
});
