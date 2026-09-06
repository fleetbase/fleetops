import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, findAll, render, waitFor } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import stubFormInputs from 'dummy/tests/helpers/stub-form-inputs';

function rows() {
    return findAll('.fleet-driver-listing .h-48 .font-semibold').map((el) => el.textContent.trim());
}

module('Integration | Component | fleet/driver-listing', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        const test = this;
        stubFormInputs(this.owner);

        this.queryFails = false;
        this.postFails = false;
        this.drivers = [
            { id: 'driver_1', name: 'Sam Driver', photo_url: null, online: true },
            { id: 'driver_2', name: 'Ada Rider', photo_url: null, online: false },
        ];
        this.owner.register(
            'service:store',
            class extends Service {
                query(modelName, params) {
                    calls.push(['query', modelName, params]);
                    if (test.queryFails) {
                        return Promise.reject(new Error('fleet unavailable'));
                    }
                    const records = test.drivers.slice();
                    records.toArray = () => test.drivers.slice();
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
                trigger(event, fleet, driver) {
                    calls.push(['trigger', event, fleet.id, driver.id]);
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

    test('it loads the fleet drivers, showing a spinner while the search runs', async function (assert) {
        this.holdQuery = true;
        const rendering = render(hbs`<Fleet::DriverListing @resource={{this.fleet}} @wrapperClass="probe" />`);

        await waitFor('.fleetbase-loader');
        assert.dom().includesText('Loading Drivers', 'the listing waits on the query, not on a debounce');
        this.releaseQuery();
        await rendering;

        assert.dom('.probe').exists('the wrapper class is applied');
        assert.dom('.fleetbase-loader').doesNotExist('the spinner clears once loaded');
        assert.deepEqual(this.calls, [['query', 'driver', { fleet: 'fleet_1', limit: -1 }]]);
        assert.dom('.fleet-driver-listing .grid').hasText('Driver', 'the column header');
        assert.deepEqual(rows(), ['Sam Driver', 'Ada Rider'], 'a row per driver');
        assert.dom('input').hasAttribute('placeholder', 'Search drivers in fleet');
        assert.dom('[data-test-model-select="driver"]').hasText('Add driver to fleet');
        assert.dom('.fleetbase-checkbox').doesNotExist('rows are not selectable by default');
    });

    test('searching requeries, and adding or removing a driver posts and announces the change', async function (assert) {
        await render(hbs`<Fleet::DriverListing @resource={{this.fleet}} />`);
        this.calls.length = 0;

        await fillIn('input', 'ada');
        assert.deepEqual(this.calls, [['query', 'driver', { fleet: 'fleet_1', query: 'ada' }]]);

        this.calls.length = 0;
        await click('[data-test-model-select="driver"]');
        assert.deepEqual(this.calls, [
            ['post', 'fleets/assign-driver', { driver: 'picked_1', fleet: 'fleet_1' }],
            ['trigger', 'fleet-ops.fleet.driver_assigned', 'fleet_1', 'picked_1'],
        ]);
        assert.deepEqual(rows(), ['Sam Driver', 'Ada Rider', 'Picked'], 'the added driver joins the list');

        this.calls.length = 0;
        await click(findAll('.fleet-driver-listing a').find((a) => /Remove/.test(a.textContent)));
        assert.deepEqual(this.calls, [
            ['post', 'fleets/remove-driver', { driver: 'driver_1', fleet: 'fleet_1' }],
            ['trigger', 'fleet-ops.fleet.driver_unassigned', 'fleet_1', 'driver_1'],
        ]);
        assert.deepEqual(rows(), ['Ada Rider', 'Picked']);
    });

    test('failures are reported and never mutate the list', async function (assert) {
        await render(hbs`<Fleet::DriverListing @resource={{this.fleet}} />`);
        this.postFails = true;
        this.calls.length = 0;

        await click('[data-test-model-select="driver"]');
        assert.deepEqual(this.calls.at(-1), ['serverError', 'assignment refused']);
        assert.deepEqual(rows(), ['Sam Driver', 'Ada Rider']);

        this.calls.length = 0;
        await click(findAll('.fleet-driver-listing a').find((a) => /Remove/.test(a.textContent)));
        assert.deepEqual(this.calls.at(-1), ['serverError', 'assignment refused']);
        assert.deepEqual(rows(), ['Sam Driver', 'Ada Rider']);

        this.queryFails = true;
        this.calls.length = 0;
        await fillIn('input', 'zzz');
        assert.deepEqual(this.calls, [['query', 'driver', { fleet: 'fleet_1', query: 'zzz' }]], 'a failed query is swallowed as debug output');
        assert.deepEqual(rows(), ['Sam Driver', 'Ada Rider'], 'the previous drivers stay listed');
    });

    test('a selectable listing reports every toggle to its caller', async function (assert) {
        const selections = [];
        this.set('onSelect', (driver) => selections.push(driver.id));
        this.set('onLoaded', (drivers) => selections.push(`loaded:${drivers.length}`));

        await render(hbs`<Fleet::DriverListing @resource={{this.fleet}} @selectable={{true}} @onLoaded={{this.onLoaded}} @onSelect={{this.onSelect}} />`);

        assert.deepEqual(selections, ['loaded:2'], 'the load callback receives the query result');
        assert.dom('.fleetbase-checkbox').exists({ count: 2 });

        await click(findAll('.fleetbase-checkbox')[0]);
        await click(findAll('.fleetbase-checkbox')[1]);
        await click(findAll('.fleetbase-checkbox')[0]);
        assert.deepEqual(selections, ['loaded:2', 'driver_1', 'driver_2', 'driver_1'], 'selecting twice deselects');
    });

    test('selectable can also arrive through the context options', async function (assert) {
        const selections = [];
        this.set('options', { selectable: true, wrapperClass: 'from-options', onSelect: (driver) => selections.push(driver.id) });

        await render(hbs`<Fleet::DriverListing @resource={{this.fleet}} @options={{this.options}} />`);

        assert.dom('.from-options').exists();
        assert.dom('.fleetbase-checkbox').exists({ count: 2 });

        await click(findAll('.fleetbase-checkbox')[1]);
        assert.deepEqual(selections, ['driver_2']);
    });
});
