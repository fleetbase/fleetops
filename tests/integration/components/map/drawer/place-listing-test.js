import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import { AbilitiesStub } from 'dummy/tests/helpers/stub-form-inputs';

module('Integration | Component | map/drawer/place-listing', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        this.owner.register('service:abilities', AbilitiesStub);
        this.places = [
            { id: 'place_1', address: '1 First St, Austin', location: { type: 'Point', coordinates: [-97.74, 30.27] } },
            { id: 'place_2', address: '2 Second Ave, Dallas', location: { type: 'Point', coordinates: [-96.8, 32.78] } },
            { id: 'place_3', location: null },
        ];
        const places = this.places;
        this.owner.register(
            'service:map-manager',
            class extends Service {
                livemap = { places };
                focusResource(resource, zoom, options) {
                    calls.push(['focusResource', resource.id, zoom]);
                    options.moveend?.();
                }
            }
        );
        this.owner.register(
            'service:place-actions',
            class extends Service {
                panel = { view: (place) => calls.push(['panel.view', place.id]), edit: (place) => calls.push(['panel.edit', place.id]) };
                delete = (place) => calls.push(['delete', place.id]);
            }
        );
    });

    test('it lists the live map places, filters them and drives the row actions', async function (assert) {
        await render(hbs`<Map::Drawer::PlaceListing />`);

        assert.dom('tbody tr').exists({ count: 3 });
        assert.dom().includesText('1 First St, Austin');
        assert.dom('input').hasAttribute('placeholder', 'Filter places by keyword...');

        await fillIn('input', 'DALLAS');
        assert.dom('tbody tr').exists({ count: 2 }, 'the match and the address-less place remain');
        assert.dom().doesNotIncludeText('1 First St');

        await fillIn('input', '');
        assert.dom('tbody tr').exists({ count: 3 });

        await click(findAll('tbody tr a').find((a) => /1 First St/.test(a.textContent)));
        assert.deepEqual(this.calls, [
            ['focusResource', 'place_1', 16],
            ['panel.view', 'place_1'],
        ]);

        this.calls.length = 0;
        await click(findAll('tbody tr')[0].querySelectorAll('a')[1]);
        assert.deepEqual(this.calls, [['focusResource', 'place_1', 18]], 'the point cell locates');

        this.calls.length = 0;
        await click(findAll('tbody tr')[1].querySelector('.cell-dropdown-button .ember-basic-dropdown-trigger'));
        assert.deepEqual(
            findAll('.next-dd-item').map((el) => el.textContent.trim()),
            ['View Place', 'Edit Place', 'Locate Place on Map', 'Delete Place']
        );
        await click(findAll('.next-dd-item').find((el) => /Edit Place/.test(el.textContent)));
        assert.deepEqual(this.calls, [
            ['focusResource', 'place_2', 16],
            ['panel.edit', 'place_2'],
        ]);

        this.calls.length = 0;
        await click(findAll('tbody tr')[1].querySelector('.cell-dropdown-button .ember-basic-dropdown-trigger'));
        await click(findAll('.next-dd-item').find((el) => /Delete Place/.test(el.textContent)));
        assert.deepEqual(this.calls, [['delete', 'place_2']]);
    });

    test('without a live map it renders the empty state', async function (assert) {
        this.owner.lookup('service:map-manager').livemap = null;
        await render(hbs`<Map::Drawer::PlaceListing />`);
        assert.dom('tbody tr td a').doesNotExist();
        assert.dom().includesText('No places visible');
    });
});
