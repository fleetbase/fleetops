import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render, waitUntil } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

class StoreStubService extends Service {
    query(modelName) {
        const data = {
            driver: [
                { name: 'Offline Driver', public_id: 'driver_2', online: false, status: 'inactive' },
                { name: 'Online Driver', public_id: 'driver_1', online: true, status: 'active' },
            ],
            vehicle: [
                { display_name: 'Offline Van', public_id: 'vehicle_2', online: false, status: 'inactive' },
                { display_name: 'Online Truck', public_id: 'vehicle_1', online: true, status: 'active' },
                { display_name: 'Standby Car', public_id: 'vehicle_3', online: false, status: 'standby' },
            ],
            fleet: [{ name: 'North Fleet', public_id: 'fleet_1' }],
        };

        return Promise.resolve(data[modelName] ?? []);
    }
}

class UniverseStubService extends Service {
    on() {}
}

class MapManagerStubService extends Service {
    livemap = {
        drivers: [],
        vehicles: [],
    };

    waitForMap() {
        return Promise.resolve();
    }

    focusResource() {}
}

class HostRouterStubService extends Service {
    transitionTo() {
        return Promise.resolve();
    }
}

class NotificationsStubService extends Service {
    serverError() {}
}

class DriverActionsStubService extends Service {
    panel = { view() {}, edit() {} };
    assignOrder() {}
    assignVehicle() {}
    delete() {}
}

class VehicleActionsStubService extends Service {
    panel = { view() {}, edit() {} };
    delete() {}
}

class FleetActionsStubService extends Service {
    panel = { view() {} };
    assignDriver() {}
    assignVehicle() {}
}

module('Integration | Component | layout/fleet-ops-sidebar/operations-monitor', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:store', StoreStubService);
        this.owner.register('service:universe', UniverseStubService);
        this.owner.register('service:map-manager', MapManagerStubService);
        this.owner.register('service:host-router', HostRouterStubService);
        this.owner.register('service:notifications', NotificationsStubService);
        this.owner.register('service:driver-actions', DriverActionsStubService);
        this.owner.register('service:vehicle-actions', VehicleActionsStubService);
        this.owner.register('service:fleet-actions', FleetActionsStubService);
    });

    test('it sorts online resources first and shows online counts', async function (assert) {
        await render(hbs`<Layout::FleetOpsSidebar::OperationsMonitor />`);
        await waitUntil(() => this.element.textContent.includes('1 drivers online'));

        assert.dom('.fleet-ops-operations-monitor').includesText('1 drivers online');
        assert.dom('.fleet-ops-operations-monitor').includesText('1 vehicles online');
        assert.dom('[data-test-operations-monitor-list]').exists('resource list has a dedicated scroll wrapper');
        assert.dom('[data-test-operations-monitor-list] [data-test-operations-monitor-row]').exists('rows render inside the scroll wrapper');
        assert.dom('[data-test-operations-monitor-tab="drivers"]').hasText('Drivers');
        assert.dom('[data-test-operations-monitor-tab="vehicles"]').hasText('Vehicles');
        assert.dom('[data-test-operations-monitor-tab="fleets"]').hasText('Fleets');
        assert.dom('[data-test-operations-monitor-row]:first-of-type').includesText('Online Driver');
        assert.dom('[data-test-operations-monitor-row]:first-of-type').includesText('Online');

        const list = this.element.querySelector('[data-test-operations-monitor-list]');

        assert.false(list.contains(this.element.querySelector('[data-test-operations-monitor-tab="drivers"]')), 'tabs stay outside the scroll wrapper');
        assert.false(list.contains(this.element.querySelector('[data-test-operations-monitor-filter]')), 'filter stays outside the scroll wrapper');

        await click('[data-test-operations-monitor-tab="vehicles"]');

        assert.dom('[data-test-operations-monitor-row]:first-of-type').includesText('Online Truck');
        assert.dom('[data-test-operations-monitor-row]:first-of-type').includesText('Online');
        assert.dom('[data-test-operations-monitor-row]:nth-of-type(2)').includesText('Offline');
    });
});
