import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { setupIntl } from 'ember-intl/test-support';

// The live map passes a vehicle model; the dashboard's Live Fleet widget passes the same
// index-resource payload as plain JSON. Both must render the same card.
const VEHICLE = {
    display_name: 'Van 7',
    online: true,
    status: 'in_service',
    internal_id: 'V-7',
    driver_name: 'Ada Driver',
    trailers: [{ display_name: 'Reefer 2', online: true }],
    devices: [],
    meta: { status_label: 'In Service', current_order_reference: 'ORD-1', speed_label: '42 km/h', heading_label: '90 deg', location_coordinates: '1.3 103.8' },
};

module('Integration | Component | map/marker-card/vehicle', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    test('it shows the vehicle card from an index-resource payload', async function (assert) {
        this.set('vehicle', VEHICLE);

        await render(hbs`<Map::MarkerCard::Vehicle @vehicle={{this.vehicle}} />`);

        assert.dom(this.element).containsText('Van 7', 'the display name falls back to display_name');
        assert.dom(this.element).containsText('In Service');
        assert.dom(this.element).containsText('V-7');
        assert.dom(this.element).containsText('Ada Driver');
        assert.dom(this.element).containsText('Reefer 2');
        assert.dom(this.element).containsText('ORD-1');
        assert.dom(this.element).containsText('42 km/h');
        assert.dom('div').hasClass('bg-gray-900', 'a popup card is its own dark panel');
    });

    test('a model display name wins and the tooltip variant drops the panel', async function (assert) {
        this.set('vehicle', { ...VEHICLE, displayName: 'Van 7 (SBA1234Z)' });

        await render(hbs`<Map::MarkerCard::Vehicle @vehicle={{this.vehicle}} @variant="tooltip" />`);

        assert.dom(this.element).containsText('Van 7 (SBA1234Z)');
        assert.dom('div').doesNotHaveClass('bg-gray-900', 'the tooltip supplies its own panel');
    });
});
