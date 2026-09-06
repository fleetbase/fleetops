import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, findAll, render, waitFor } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import stubFormInputs from 'dummy/tests/helpers/stub-form-inputs';

function rows() {
    return findAll('.fleet-vehicle-listing .h-48 .font-semibold').map((el) => el.textContent.trim());
}

function rowCount() {
    return findAll('.fleet-vehicle-listing .h-48 a').length;
}

module('Integration | Component | fleet/vehicle-listing', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        const test = this;
        stubFormInputs(this.owner);

        this.queryFails = false;
        this.postFails = false;
        this.vehicles = [
            { id: 'vehicle_1', displayName: 'Truck 1', photo_url: null, online: true },
            { id: 'vehicle_2', displayName: 'Van 2', photo_url: null, online: false },
        ];
        this.owner.register(
            'service:store',
            class extends Service {
                query(modelName, params) {
                    calls.push(['query', modelName, params]);
                    if (test.queryFails) {
                        return Promise.reject(new Error('fleet unavailable'));
                    }
                    const records = test.vehicles.slice();
                    records.toArray = () => test.vehicles.slice();
                    if (test.holdQuery) {
                        return new Promise((resolve) => {
                            test.releaseQuery = () => resolve(records);
                        });
                    }
                    return Promise.resolve(records);
                }
            }
        );
        this.owner.register(
            'service:fetch',
            class extends Service {
                async post(path, body) {
                    calls.push(['post', path, body]);
                    if (test.postFails) {
                        throw new Error('assignment refused');
                    }
                }
            }
        );
        this.owner.register(
            'service:universe',
            class extends Service {
                trigger(event, fleet, vehicle) {
                    calls.push(['trigger', event, fleet.id, vehicle.id]);
                }
            }
        );
        this.owner.register(
            'service:notifications',
            class extends Service {
                serverError(error) {
                    calls.push(['serverError', error.message]);
                }
            }
        );
        this.set('fleet', { id: 'fleet_1', name: 'North' });
    });

    test('it loads the fleet vehicles, showing a spinner while the search runs', async function (assert) {
        this.holdQuery = true;
        const rendering = render(hbs`<Fleet::VehicleListing @resource={{this.fleet}} @wrapperClass="probe" />`);

        await waitFor('.fleetbase-loader');
        assert.dom().includesText('Loading Vehicles', 'the listing waits on the query, not on a debounce');
        this.releaseQuery();
        await rendering;

        assert.dom('.probe').exists('the wrapper class is applied');
        assert.dom('.fleetbase-loader').doesNotExist('the spinner clears once loaded');
        assert.deepEqual(this.calls, [['query', 'vehicle', { fleet: 'fleet_1', limit: -1 }]]);
        assert.dom('.fleet-vehicle-listing .grid').hasText('Vehicle', 'the column header');
        assert.deepEqual(rows(), ['Truck 1', 'Van 2'], 'a row per vehicle');
        assert.dom('input').hasAttribute('placeholder', 'Search vehicle in fleet');
        assert.dom('[data-test-model-select="vehicle"]').hasText('Add vehicle to fleet');
        assert.dom('.fleetbase-checkbox').doesNotExist('rows are not selectable by default');
    });

    test('searching requeries, and adding or removing a vehicle posts and announces the change', async function (assert) {
        await render(hbs`<Fleet::VehicleListing @resource={{this.fleet}} />`);
        this.calls.length = 0;

        await fillIn('input', 'van');
        assert.deepEqual(this.calls, [['query', 'vehicle', { fleet: 'fleet_1', query: 'van' }]]);

        this.calls.length = 0;
        await click('[data-test-model-select="vehicle"]');
        assert.deepEqual(this.calls, [
            ['post', 'fleets/assign-vehicle', { vehicle: 'picked_1', fleet: 'fleet_1' }],
            ['trigger', 'fleet-ops.fleet.vehicle_assigned', 'fleet_1', 'picked_1'],
        ]);
        assert.strictEqual(rowCount(), 3, 'the added vehicle joins the list');

        this.calls.length = 0;
        await click(findAll('.fleet-vehicle-listing a').find((a) => /Remove/.test(a.textContent)));
        assert.deepEqual(this.calls, [
            ['post', 'fleets/remove-vehicle', { vehicle: 'vehicle_1', fleet: 'fleet_1' }],
            ['trigger', 'fleet-ops.fleet.vehicle_unassigned', 'fleet_1', 'vehicle_1'],
        ]);
        assert.deepEqual(rows(), ['Van 2', ''], 'the removed vehicle is gone; the added one has no displayName on the stub record');
    });

    test('failures are reported and never mutate the list', async function (assert) {
        await render(hbs`<Fleet::VehicleListing @resource={{this.fleet}} />`);
        this.postFails = true;
        this.calls.length = 0;

        await click('[data-test-model-select="vehicle"]');
        assert.deepEqual(this.calls.at(-1), ['serverError', 'assignment refused']);
        assert.deepEqual(rows(), ['Truck 1', 'Van 2']);

        this.calls.length = 0;
        await click(findAll('.fleet-vehicle-listing a').find((a) => /Remove/.test(a.textContent)));
        assert.deepEqual(this.calls.at(-1), ['serverError', 'assignment refused']);
        assert.deepEqual(rows(), ['Truck 1', 'Van 2']);

        this.queryFails = true;
        this.calls.length = 0;
        await fillIn('input', 'zzz');
        assert.deepEqual(this.calls, [['query', 'vehicle', { fleet: 'fleet_1', query: 'zzz' }]], 'a failed query is swallowed as debug output');
        assert.deepEqual(rows(), ['Truck 1', 'Van 2'], 'the previous vehicles stay listed');
    });

    test('a selectable listing reports every toggle to its caller', async function (assert) {
        const selections = [];
        this.set('onSelect', (vehicle) => selections.push(vehicle.id));
        this.set('onLoaded', (vehicles) => selections.push(`loaded:${vehicles.length}`));

        await render(hbs`<Fleet::VehicleListing @resource={{this.fleet}} @selectable={{true}} @onLoaded={{this.onLoaded}} @onSelect={{this.onSelect}} />`);

        assert.deepEqual(selections, ['loaded:2'], 'the load callback receives the query result');
        assert.dom('.fleetbase-checkbox').exists({ count: 2 });

        await click(findAll('.fleetbase-checkbox')[0]);
        await click(findAll('.fleetbase-checkbox')[1]);
        await click(findAll('.fleetbase-checkbox')[0]);
        assert.deepEqual(selections, ['loaded:2', 'vehicle_1', 'vehicle_2', 'vehicle_1'], 'selecting twice deselects');
    });

    test('selectable can also arrive through the context options', async function (assert) {
        const selections = [];
        this.set('options', { selectable: true, wrapperClass: 'from-options', onSelect: (vehicle) => selections.push(vehicle.id) });

        await render(hbs`<Fleet::VehicleListing @resource={{this.fleet}} @options={{this.options}} />`);

        assert.dom('.from-options').exists();
        assert.dom('.fleetbase-checkbox').exists({ count: 2 });

        await click(findAll('.fleetbase-checkbox')[1]);
        assert.deepEqual(selections, ['vehicle_2']);
    });
});
