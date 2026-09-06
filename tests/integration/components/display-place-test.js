import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

const PLACE = {
    name: 'Warehouse',
    street1: '1 Harbour Road',
    street2: 'Unit 4',
    city: 'Singapore',
    province: 'Central',
    postal_code: '018989',
    neighborhood: 'Docklands',
    district: 'South',
    building: 'Block C',
    country: 'SG',
    country_name: 'Singapore',
    phone: '+6512345678',
};

module('Integration | Component | display-place', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register(
            'service:abilities',
            class extends Service {
                can() {
                    return true;
                }

                cannot() {
                    return false;
                }
            }
        );
    });

    test('it renders the full address from @place, a wrapped place or @resource', async function (assert) {
        this.set('place', PLACE);
        await render(hbs`<DisplayPlace @place={{this.place}} />`);

        assert.dom('address .font-semibold').hasText('Warehouse');
        assert.dom(this.element).includesText('1 Harbour Road').includesText('Unit 4').includesText('Singapore, Central, 018989').includesText('Docklands, South, Block C');
        assert.dom('a[href="tel:+6512345678"]').hasText('+6512345678');
        assert.dom(this.element).includesText('Singapore');

        this.set('place', { place: { street1: '2 Wrapped Street' } });
        await render(hbs`<DisplayPlace @place={{this.place}} />`);
        assert.dom('address .font-semibold').hasText('2 Wrapped Street', 'a waypoint wrapper is unwrapped');

        this.set('place', { street1: '3 Resource Street' });
        await render(hbs`<DisplayPlace @resource={{this.place}} />`);
        assert.dom('address .font-semibold').hasText('3 Resource Street');
    });

    test('a name equal to the street is not repeated and empty lines are skipped', async function (assert) {
        this.set('place', { name: '1 Harbour Road', street1: '1 Harbour Road', city: 'Singapore' });

        await render(hbs`<DisplayPlace @place={{this.place}} />`);

        assert.strictEqual(findAll('address div').length, 2, 'street and city only');
        assert.dom('address .font-semibold').hasText('1 Harbour Road');
        assert.dom(this.element).includesText('Singapore');
        assert.dom('address a').doesNotExist();
    });

    test('without a place it explains that no address is set', async function (assert) {
        await render(hbs`<DisplayPlace @place={{null}} @type="pickup" />`);
        assert.dom('.text-red-500').hasText('No pickup address!');

        await render(hbs`<DisplayPlace @place={{null}} />`);
        assert.dom('.text-red-500').hasText('No address!');
    });

    test('waypoint actions render the status and eta badges and a dropdown of actions', async function (assert) {
        const calls = [];
        this.set('place', { ...PLACE, status_code: 'completed' });
        this.set('actions', {
            edit: { label: 'Edit stop', fn: (context) => calls.push(['edit', context]) },
            remove: { label: 'Remove stop', fn: (context) => calls.push(['remove', context]) },
        });

        await render(hbs`<DisplayPlace @place={{this.place}} @waypointActions={{this.actions}} @eta={{3600}} />`);
        assert.dom('.status-badge').exists({ count: 2 }, 'status and eta badges');
        assert.dom(this.element).includesText('ETA');

        await click('.ember-basic-dropdown-trigger');
        assert.deepEqual(
            findAll('.next-dd-item').map((element) => element.textContent.trim()),
            ['Edit stop', 'Remove stop']
        );
        await click(findAll('.next-dd-item')[1]);
        assert.deepEqual(calls, [['remove', this.place]], 'the action receives the place by default');

        this.set('context', { id: 'waypoint_1' });
        await render(hbs`<DisplayPlace @place={{this.place}} @waypointActions={{this.actions}} @waypointActionContext={{this.context}} @hideBadges={{true}} />`);
        assert.dom('.status-badge').doesNotExist('badges can be hidden');
        await click('.ember-basic-dropdown-trigger');
        await click('.next-dd-item');
        assert.deepEqual(calls.at(-1), ['edit', this.context], 'an explicit context wins');
    });
});
