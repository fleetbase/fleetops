import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import { AbilitiesStub } from 'dummy/tests/helpers/stub-form-inputs';

module('Integration | Component | map/drawer/vehicle-listing', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        this.owner.register('service:abilities', AbilitiesStub);
        this.vehicles = [
            {
                id: 'vehicle_1',
                display_name: 'Truck 1',
                searchString: 'truck 1 abc-123',
                status: 'available',
                updatedAgo: '1m ago',
                location: { type: 'Point', coordinates: [-97.74, 30.27] },
            },
            { id: 'vehicle_2', display_name: 'Van 2', searchString: 'van 2 xyz-999', status: 'in_use', updatedAgo: '5m ago', location: { type: 'Point', coordinates: [-96.8, 32.78] } },
            { id: 'vehicle_3', display_name: 'Nameless' },
        ];
        const vehicles = this.vehicles;
        this.owner.register(
            'service:map-manager',
            class extends Service {
                livemap = { vehicles };
                focusResource(resource, zoom, options) {
                    calls.push(['focusResource', resource.id, zoom]);
                    options.moveend?.();
                }
            }
        );
        this.owner.register(
            'service:vehicle-actions',
            class extends Service {
                panel = { view: (vehicle) => calls.push(['panel.view', vehicle.id]), edit: (vehicle) => calls.push(['panel.edit', vehicle.id]) };
                delete = (vehicle) => calls.push(['delete', vehicle.id]);
            }
        );
    });

    test('it lists the live map vehicles, filters them and drives the row actions', async function (assert) {
        await render(hbs`<Map::Drawer::VehicleListing />`);

        assert.dom('tbody tr').exists({ count: 3 });
        assert.dom().includesText('Truck 1');
        assert.dom().includesText('1m ago');
        assert.dom('input').hasAttribute('placeholder', 'Filter vehicles by keyword...');

        await fillIn('input', 'XYZ');
        assert.dom('tbody tr').exists({ count: 2 }, 'the match and the vehicle without a search string remain');
        assert.dom().doesNotIncludeText('Truck 1');
        await fillIn('input', '');

        await click(findAll('tbody tr a').find((a) => /Truck 1/.test(a.textContent)));
        assert.deepEqual(this.calls, [
            ['focusResource', 'vehicle_1', 16],
            ['panel.view', 'vehicle_1'],
        ]);

        this.calls.length = 0;
        await click(findAll('tbody tr')[0].querySelectorAll('a')[1]);
        assert.deepEqual(this.calls, [['focusResource', 'vehicle_1', 18]], 'the point cell locates');

        this.calls.length = 0;
        await click(findAll('tbody tr')[1].querySelector('.cell-dropdown-button .ember-basic-dropdown-trigger'));
        assert.deepEqual(
            findAll('.next-dd-item').map((el) => el.textContent.trim()),
            ['View Vehicle', 'Edit Vehicle', 'Locate Vehicle on Map', 'Delete Vehicle']
        );
        await click(findAll('.next-dd-item').find((el) => /Edit Vehicle/.test(el.textContent)));
        assert.deepEqual(this.calls, [
            ['focusResource', 'vehicle_2', 16],
            ['panel.edit', 'vehicle_2'],
        ]);

        this.calls.length = 0;
        await click(findAll('tbody tr')[1].querySelector('.cell-dropdown-button .ember-basic-dropdown-trigger'));
        await click(findAll('.next-dd-item').find((el) => /Locate Vehicle/.test(el.textContent)));
        assert.deepEqual(this.calls, [['focusResource', 'vehicle_2', 18]]);

        this.calls.length = 0;
        await click(findAll('tbody tr')[1].querySelector('.cell-dropdown-button .ember-basic-dropdown-trigger'));
        await click(findAll('.next-dd-item').find((el) => /Delete Vehicle/.test(el.textContent)));
        assert.deepEqual(this.calls, [['delete', 'vehicle_2']]);
    });

    test('without a live map it renders the empty state', async function (assert) {
        this.owner.lookup('service:map-manager').livemap = null;
        await render(hbs`<Map::Drawer::VehicleListing />`);
        assert.dom().includesText('No vehicles visible');
    });
});
