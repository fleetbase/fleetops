import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | map/marker-card/driver', function (hooks) {
    setupRenderingTest(hooks);

    test('it shows the driver card from an index-resource payload', async function (assert) {
        this.set('driver', {
            name: 'Ada Driver',
            online: true,
            status: 'active',
            public_id: 'driver_public',
            phone: '+6590000000',
            vehicle_name: 'Van 7',
            email: 'ada@example.test',
            meta: { status_label: 'Active', current_order_reference: 'ORD-1', speed_label: '42 km/h', heading_label: '90 deg', location_coordinates: '1.3 103.8' },
        });

        await render(hbs`<Map::MarkerCard::Driver @driver={{this.driver}} />`);

        for (const text of ['Ada Driver', 'Active', 'driver_public', '+6590000000', 'Van 7', 'ada@example.test', 'ORD-1', '42 km/h', '90 deg', '1.3 103.8']) {
            assert.dom(this.element).containsText(text);
        }
    });

    test('the tooltip variant drops the dark panel', async function (assert) {
        this.set('driver', { name: 'Ada Driver', meta: {} });

        await render(hbs`<Map::MarkerCard::Driver @driver={{this.driver}} @variant="tooltip" />`);

        assert.dom('div').doesNotHaveClass('bg-gray-900');
    });
});
