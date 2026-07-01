import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, render, waitUntil } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

class StoreServiceStub extends Service {
    queryCalls = [];
    driverResults = [];
    vehicleResults = [];

    async query(modelName, params) {
        this.queryCalls.push({ modelName, params });

        const results = modelName === 'driver' ? this.driverResults : this.vehicleResults;

        return {
            toArray() {
                return results;
            },
        };
    }
}

module('Integration | Component | fleet/hierarchy-tree', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:store', StoreServiceStub);
        this.store = this.owner.lookup('service:store');
        this.fleet = {
            id: 'fleet_root',
            name: 'Central Fleet',
            drivers_count: 2,
            drivers_online_count: 1,
            vehicles_count: 2,
            vehicles_online_count: 1,
            drivers: [
                { id: 'driver_1', name: 'Ari Driver', online: true, vehicle_name: 'Van 11' },
                { id: 'driver_2', name: 'Bo Driver', online: false, status: 'standby' },
            ],
            vehicles: [
                { id: 'vehicle_1', displayName: 'Van 11', online: true, driver_name: 'Ari Driver' },
                { id: 'vehicle_2', displayName: 'Truck 12', online: false, plate_number: 'FB-12' },
            ],
            subfleets: [
                {
                    id: 'fleet_child',
                    name: 'North Subfleet',
                    drivers_count: 1,
                    drivers_online_count: 1,
                    vehicles_count: 1,
                    vehicles_online_count: 0,
                    drivers: [{ id: 'driver_3', name: 'Cy Driver', online: true }],
                    vehicles: [{ id: 'vehicle_3', displayName: 'North Truck', online: false }],
                    subfleets: [],
                },
            ],
        };
    });

    test('it renders a nested fleet hierarchy with drivers and vehicles', async function (assert) {
        await render(hbs`<Fleet::HierarchyTree @fleet={{this.fleet}} />`);

        assert.dom('[data-test-fleet-hierarchy-tree]').exists();
        assert.dom('[data-test-fleet-hierarchy-row="fleet"]').exists({ count: 2 });
        assert.dom('[data-test-fleet-hierarchy-row="driver"]').exists({ count: 3 });
        assert.dom('[data-test-fleet-hierarchy-row="vehicle"]').exists({ count: 3 });
        assert.dom().includesText('Central Fleet');
        assert.dom().includesText('North Subfleet');
        assert.dom().includesText('Ari Driver');
        assert.dom().includesText('Van 11');
    });

    test('it collapses and expands fleet nodes', async function (assert) {
        await render(hbs`<Fleet::HierarchyTree @fleet={{this.fleet}} />`);

        assert.dom('[data-test-fleet-hierarchy-row="driver"]').exists({ count: 3 });

        await click('[data-test-fleet-hierarchy-row="fleet"] button');

        assert.dom('[data-test-fleet-hierarchy-row="driver"]').doesNotExist();
        assert.dom('[data-test-fleet-hierarchy-row="vehicle"]').doesNotExist();

        await click('[data-test-fleet-hierarchy-row="fleet"] button');

        assert.dom('[data-test-fleet-hierarchy-row="driver"]').exists({ count: 3 });
    });

    test('it uses a single icon-only control to expand and collapse all fleet nodes', async function (assert) {
        await render(hbs`<Fleet::HierarchyTree @fleet={{this.fleet}} />`);

        assert.dom('[data-test-fleet-hierarchy-expand-toggle]').exists({ count: 1 });
        assert.dom('[data-test-fleet-hierarchy-expand-all]').doesNotExist();
        assert.dom('[data-test-fleet-hierarchy-collapse-all]').doesNotExist();
        assert.dom().includesText('North Truck');

        await click('[data-test-fleet-hierarchy-expand-toggle]');

        assert.dom().includesText('Ari Driver');
        assert.dom().doesNotIncludeText('North Truck');

        await click('[data-test-fleet-hierarchy-expand-toggle]');

        assert.dom().includesText('North Truck');
    });

    test('it filters by search text', async function (assert) {
        await render(hbs`<Fleet::HierarchyTree @fleet={{this.fleet}} />`);

        await fillIn('[data-test-fleet-hierarchy-search]', 'North Truck');

        assert.dom('[data-test-fleet-hierarchy-row="fleet"]').exists({ count: 2 });
        assert.dom('[data-test-fleet-hierarchy-row="vehicle"]').exists({ count: 1 });
        assert.dom().includesText('North Truck');
        assert.dom().doesNotIncludeText('Ari Driver');
    });

    test('it filters by resource type and online state', async function (assert) {
        await render(hbs`<Fleet::HierarchyTree @fleet={{this.fleet}} />`);

        await click('[data-test-fleet-hierarchy-filter="drivers"]');

        assert.dom('[data-test-fleet-hierarchy-row="driver"]').exists({ count: 3 });
        assert.dom('[data-test-fleet-hierarchy-row="vehicle"]').doesNotExist();

        await click('[data-test-fleet-hierarchy-filter="online"]');

        assert.dom('[data-test-fleet-hierarchy-row="driver"]').exists({ count: 2 });
        assert.dom('[data-test-fleet-hierarchy-row="vehicle"]').exists({ count: 1 });
        assert.dom().doesNotIncludeText('Bo Driver');
        assert.dom().doesNotIncludeText('Truck 12');
    });

    test('it shows empty and no-results states', async function (assert) {
        this.set('fleet', { id: 'empty_fleet', name: 'Empty Fleet', subfleets: [], drivers: [], vehicles: [] });

        await render(hbs`<Fleet::HierarchyTree @fleet={{this.fleet}} />`);

        assert.dom('[data-test-fleet-hierarchy-empty]').exists();
        assert.dom().includesText('No fleet resources assigned');

        await fillIn('[data-test-fleet-hierarchy-search]', 'missing');

        assert.dom('[data-test-fleet-hierarchy-empty]').exists();
        assert.dom().includesText('No matching resources');

        await click('[data-test-fleet-hierarchy-clear-filters]');

        assert.dom().includesText('No fleet resources assigned');
    });

    test('it loads direct fleet resources when counts exist but embedded rows are missing', async function (assert) {
        this.store.driverResults = [{ id: 'driver_loaded', name: 'Loaded Driver', online: true }];
        this.store.vehicleResults = [{ id: 'vehicle_loaded', displayName: 'Loaded Vehicle', online: false }];
        this.set('fleet', {
            id: 'fleet_with_counts',
            name: 'Counted Fleet',
            drivers_count: 1,
            drivers_online_count: 1,
            vehicles_count: 1,
            vehicles_online_count: 0,
            drivers: [],
            vehicles: [],
            subfleets: [],
        });

        await render(hbs`<Fleet::HierarchyTree @fleet={{this.fleet}} />`);
        await waitUntil(() => this.element.textContent.includes('Loaded Driver'));

        assert.deepEqual(
            this.store.queryCalls.map((call) => [call.modelName, call.params]),
            [
                ['driver', { fleet: 'fleet_with_counts', limit: -1 }],
                ['vehicle', { fleet: 'fleet_with_counts', limit: -1 }],
            ]
        );
        assert.dom('[data-test-fleet-hierarchy-row="driver"]').exists({ count: 1 });
        assert.dom('[data-test-fleet-hierarchy-row="vehicle"]').exists({ count: 1 });
        assert.dom().includesText('Loaded Driver');
        assert.dom().includesText('Loaded Vehicle');
    });
});
