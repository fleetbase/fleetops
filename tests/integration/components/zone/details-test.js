import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | zone/details', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the zone details and its polygon on a map', async function (assert) {
        this.set('resource', {
            id: 'zone_1',
            name: 'North',
            type: 'delivery',
            color: '#00ff00',
            stroke_color: '#000000',
            firstCoordinatePairLatitude: 1.4,
            firstCoordinatePairLongitude: 103.8,
            leafletCoordinates: [
                [
                    [103.8, 1.4],
                    [103.9, 1.4],
                    [103.9, 1.5],
                    [103.8, 1.4],
                ],
            ],
        });

        await render(hbs`<Zone::Details @resource={{this.resource}} />`);

        assert.dom(this.element).includesText('North').includesText('delivery');
        assert.dom('.leaflet-container').exists();
        assert.dom('.leaflet-overlay-pane path').exists('the polygon is drawn');
        assert.dom('.leaflet-tooltip').includesText('North Zone');
    });

    test('missing details render as dashes', async function (assert) {
        this.set('resource', {
            id: 'zone_2',
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

        await render(hbs`<Zone::Details @resource={{this.resource}} />`);

        assert.strictEqual(findAll('.field-value').filter((element) => element.textContent.trim() === '-').length, 2);
    });
});
