import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, render, waitUntil } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

class StoreStubService extends Service {
    records = new Map();

    pushPayload(modelName, payload) {
        const resource = payload[modelName];

        if (resource?.id) {
            this.records.set(`${modelName}:${resource.id}`, resource);
        }

        if (resource?.public_id) {
            this.records.set(`${modelName}:${resource.public_id}`, resource);
        }
    }

    peekRecord(modelName, id) {
        return this.records.get(`${modelName}:${id}`);
    }
}

class FetchStubService extends Service {
    get() {
        return Promise.resolve({
            drivers: [
                { id: 'driver_2', name: 'Offline Driver', public_id: 'driver_2', online: false, status: 'inactive' },
                { id: 'driver_1', name: 'Online Driver', public_id: 'driver_1', online: true, status: 'active' },
            ],
            vehicles: [
                { id: 'vehicle_2', display_name: 'Offline Van', public_id: 'vehicle_2', online: false, status: 'inactive' },
                { id: 'vehicle_1', display_name: 'Online Truck', public_id: 'vehicle_1', online: true, status: 'active' },
                { id: 'vehicle_3', display_name: 'Standby Car', public_id: 'vehicle_3', online: false, status: 'standby' },
            ],
            fleets: [
                {
                    id: 'fleet_1',
                    name: 'North Fleet',
                    public_id: 'fleet_1',
                    driver_ids: ['driver_1', 'missing_driver'],
                    vehicle_ids: ['vehicle_1'],
                    drivers_count: 1,
                    vehicles_count: 1,
                    subfleets: [
                        {
                            id: 'fleet_2',
                            name: 'North Subfleet',
                            public_id: 'fleet_2',
                            driver_ids: ['driver_2'],
                            vehicle_ids: ['missing_vehicle'],
                            drivers_count: 1,
                            vehicles_count: 0,
                            subfleets: [],
                        },
                    ],
                },
            ],
        });
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
        this.owner.register('service:fetch', FetchStubService);
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

        await click('[data-test-operations-monitor-tab="drivers"]');

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

    test('it renders fleet children from composite response id links', async function (assert) {
        await render(hbs`<Layout::FleetOpsSidebar::OperationsMonitor />`);
        await waitUntil(() => this.element.textContent.includes('North Fleet'));

        assert.dom('[data-test-operations-monitor-list]').includesText('North Fleet');
        assert.dom('[data-test-operations-monitor-list]').includesText('North Subfleet');
        assert.dom('[data-test-operations-monitor-list]').includesText('Online Driver');
        assert.dom('[data-test-operations-monitor-list]').includesText('Online Truck');
        assert.dom('[data-test-operations-monitor-list]').doesNotIncludeText('missing_driver');
        assert.dom('[data-test-operations-monitor-list]').doesNotIncludeText('missing_vehicle');

        await fillIn('[data-test-operations-monitor-filter]', 'offline');

        assert.dom('[data-test-operations-monitor-list]').includesText('North Fleet');
        assert.dom('[data-test-operations-monitor-list]').includesText('North Subfleet');
        assert.dom('[data-test-operations-monitor-list]').includesText('Offline Driver');
    });
});
