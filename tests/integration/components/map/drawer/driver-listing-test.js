import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import { AbilitiesStub } from 'dummy/tests/helpers/stub-form-inputs';

module('Integration | Component | map/drawer/driver-listing', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        this.owner.register('service:abilities', AbilitiesStub);
        const drivers = [
            { id: 'driver_1', name: 'Sam Driver', status: 'active', updatedAgo: '1m ago', current_job_id: 'order_1', location: { type: 'Point', coordinates: [-97.74, 30.27] } },
            { id: 'driver_2', name: 'Ada Rider', status: 'inactive', updatedAgo: '5m ago', current_job_id: null, location: { type: 'Point', coordinates: [-96.8, 32.78] } },
            { id: 'driver_3' },
        ];
        this.drivers = drivers;
        this.owner.register(
            'service:map-manager',
            class extends Service {
                livemap = { drivers };
                focusResource(resource, zoom, options) {
                    calls.push(['focusResource', resource.id, zoom]);
                    options.moveend?.();
                }
            }
        );
        this.owner.register(
            'service:driver-actions',
            class extends Service {
                panel = { view: (driver) => calls.push(['panel.view', driver.id]), edit: (driver) => calls.push(['panel.edit', driver.id]) };
                assignOrder = (driver) => calls.push(['assignOrder', driver.id]);
                assignVehicle = (driver) => calls.push(['assignVehicle', driver.id]);
                delete = (driver) => calls.push(['delete', driver.id]);
            }
        );
        this.owner.register(
            'service:host-router',
            class extends Service {
                transitionTo(route, model) {
                    calls.push(['transitionTo', route, model]);
                }
            }
        );
    });

    test('it lists the live map drivers, filters them by name and drives the row actions', async function (assert) {
        await render(hbs`<Map::Drawer::DriverListing />`);

        assert.dom('tbody tr').exists({ count: 3 });
        assert.dom().includesText('Sam Driver');
        assert.dom().includesText('1m ago');
        assert.dom('input').hasAttribute('placeholder', 'Filter drivers by keyword...');

        await fillIn('input', 'ADA');
        assert.dom('tbody tr').exists({ count: 2 }, 'the match and the driver without a name remain');
        assert.dom().doesNotIncludeText('Sam Driver');
        await fillIn('input', '');

        await click(findAll('tbody tr a').find((a) => /Sam Driver/.test(a.textContent)));
        assert.deepEqual(this.calls, [
            ['focusResource', 'driver_1', 16],
            ['panel.view', 'driver_1'],
        ]);

        this.calls.length = 0;
        await click(findAll('tbody tr')[0].querySelectorAll('a')[1]);
        assert.deepEqual(this.calls, [['focusResource', 'driver_1', 18]], 'the point cell locates the driver');

        this.calls.length = 0;
        await click(findAll('tbody tr')[0].querySelectorAll('a')[2]);
        assert.deepEqual(this.calls, [['transitionTo', 'console.fleet-ops.operations.orders.index.details', 'order_1']], 'the current job cell opens the order');

        this.calls.length = 0;
        await click(findAll('tbody tr')[1].querySelectorAll('a')[2]);
        assert.deepEqual(this.calls, [], 'a driver without a current job does not transition');
    });

    test('the row dropdown exposes every driver action', async function (assert) {
        await render(hbs`<Map::Drawer::DriverListing />`);

        await click(findAll('tbody tr')[1].querySelector('.cell-dropdown-button .ember-basic-dropdown-trigger'));
        assert.deepEqual(
            findAll('.next-dd-item').map((el) => el.textContent.trim()),
            ['View Driver', 'Edit Driver', 'Assign Order to Driver', 'Assign Vehicle to Driver', 'Locate Driver on Map', 'Delete Driver']
        );

        const run = async (label) => {
            this.calls.length = 0;
            await click(findAll('tbody tr')[1].querySelector('.cell-dropdown-button .ember-basic-dropdown-trigger'));
            await click(findAll('.next-dd-item').find((el) => el.textContent.trim() === label));
        };

        await click(findAll('.next-dd-item').find((el) => /Edit Driver/.test(el.textContent)));
        assert.deepEqual(this.calls, [
            ['focusResource', 'driver_2', 16],
            ['panel.edit', 'driver_2'],
        ]);

        await run('Locate Driver on Map');
        assert.deepEqual(this.calls, [['focusResource', 'driver_2', 18]]);

        await run('Assign Order to Driver');
        assert.deepEqual(this.calls, [['assignOrder', 'driver_2']]);

        await run('Assign Vehicle to Driver');
        assert.deepEqual(this.calls, [['assignVehicle', 'driver_2']]);

        await run('Delete Driver');
        assert.deepEqual(this.calls, [['delete', 'driver_2']]);
    });

    test('without a live map it renders the empty state', async function (assert) {
        this.owner.lookup('service:map-manager').livemap = null;

        await render(hbs`<Map::Drawer::DriverListing />`);

        assert.dom('tbody tr td.next-table-empty-state-cell').exists();
        assert.dom().includesText('No drivers visible');
    });
});
