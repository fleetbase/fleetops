import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

class FleetActionsServiceStub extends Service {
    panel = {
        view() {},
    };

    assignDriver() {}
    assignVehicle() {}
}

class DriverActionsServiceStub extends Service {
    panel = {
        view() {},
    };
}

class VehicleActionsServiceStub extends Service {
    panel = {
        view() {},
    };
}

module('Integration | Component | fleet/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fleet-actions', FleetActionsServiceStub);
        this.owner.register('service:driver-actions', DriverActionsServiceStub);
        this.owner.register('service:vehicle-actions', VehicleActionsServiceStub);
    });

    test('it renders fleet details and hierarchy without KPI summary noise', async function (assert) {
        this.set('fleet', {
            id: 'fleet_1',
            name: 'Central Fleet',
            status: 'active',
            task: 'Same-day delivery',
            drivers_count: 2,
            drivers_online_count: 1,
            vehicles_count: 1,
            vehicles_online_count: 1,
            createdAtShort: '30, Jun',
            service_area: { name: 'Ulaanbaatar' },
            zone: { name: 'North' },
            drivers: [{ id: 'driver_1', name: 'Ari Driver', online: true }],
            vehicles: [{ id: 'vehicle_1', displayName: 'Van 11', online: true }],
            subfleets: [{ id: 'fleet_child', name: 'North Subfleet', drivers: [], vehicles: [], subfleets: [] }],
        });

        await render(hbs`<Fleet::Details @resource={{this.fleet}} />`);

        assert.dom('[data-test-fleet-details-summary]').doesNotExist();
        assert.dom().includesText('Central Fleet');
        assert.dom().includesText('Same-day delivery');
        assert.dom().includesText('1 of 2 Online');
        assert.dom('[data-test-fleet-hierarchy-tree]').exists();
        assert.dom().includesText('North Subfleet');
        assert.dom().includesText('Ari Driver');
        assert.dom().includesText('Van 11');
    });
});
