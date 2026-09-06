import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | modals/point-map', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders a map marker for the point and shows the popup text and tooltip', async function (assert) {
        this.set('options', {
            title: 'Pickup location',
            latitude: 1.3,
            longitude: 103.8,
            location: { type: 'Point', coordinates: [103.8, 1.3] },
            popupText: 'Warehouse gate',
            tooltip: 'Pickup',
        });

        await render(hbs`<Modals::PointMap @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom(this.element).includesText('Pickup location');
        assert.dom('.leaflet-container').exists('the map renders');
        assert.dom('.leaflet-marker-icon').exists('the point is marked');

        await click('.leaflet-marker-icon');
        assert.dom('.leaflet-popup-content').includesText('Warehouse gate').includesText('(1.3, 103.8)');
    });

    test('it renders without popup text or tooltip', async function (assert) {
        this.set('options', { latitude: 1.3, longitude: 103.8, location: { type: 'Point', coordinates: [103.8, 1.3] } });

        await render(hbs`<Modals::PointMap @modalIsOpened={{true}} @options={{this.options}} />`);
        await click('.leaflet-marker-icon');

        assert.dom('.leaflet-popup-content').hasText('(1.3, 103.8)', 'only the coordinates are shown');
        assert.dom('.leaflet-tooltip').doesNotExist();
    });
});
