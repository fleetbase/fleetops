import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | service-area/details', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the details and the service area polygon on a map', async function (assert) {
        this.set('resource', {
            id: 'service_area_1',
            name: 'Central',
            type: 'city',
            country: 'SG',
            color: '#ff0000',
            stroke_color: '#0000ff',
            firstCoordinatePairLatitude: 1.3,
            firstCoordinatePairLongitude: 103.8,
            leafletCoordinates: [
                [
                    [103.8, 1.3],
                    [103.9, 1.3],
                    [103.9, 1.4],
                    [103.8, 1.3],
                ],
            ],
        });

        await render(hbs`<ServiceArea::Details @resource={{this.resource}} />`);

        assert.dom(this.element).includesText('Central').includesText('city').includesText('SG');
        assert.dom('.leaflet-container').exists('the map renders');
        assert.dom('.leaflet-overlay-pane path').exists('the polygon is drawn');
        assert.dom('.leaflet-tooltip').includesText('Central Service Area');
    });

    test('missing details render as dashes', async function (assert) {
        this.set('resource', {
            id: 'service_area_2',
            firstCoordinatePairLatitude: 1.3,
            firstCoordinatePairLongitude: 103.8,
            leafletCoordinates: [
                [
                    [103.8, 1.3],
                    [103.9, 1.3],
                    [103.9, 1.4],
                ],
            ],
        });

        await render(hbs`<ServiceArea::Details @resource={{this.resource}} />`);

        assert.strictEqual(findAll('.field-value').filter((element) => element.textContent.trim() === '-').length, 3);
    });
});
