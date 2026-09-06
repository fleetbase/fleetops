import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

module('Integration | Helper | leaflet-tile-url', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register(
            'service:map-settings',
            class extends Service {
                getLeafletTileUrl(theme) {
                    return `https://tiles.example/${theme}/{z}/{x}/{y}.png`;
                }
            }
        );
    });

    test('it resolves the light tile url by default and honours an explicit theme', async function (assert) {
        await render(hbs`<div data-test-url={{leaflet-tile-url}}></div>`);
        assert.dom('[data-test-url]').hasAttribute('data-test-url', 'https://tiles.example/light/{z}/{x}/{y}.png');

        await render(hbs`<div data-test-url={{leaflet-tile-url theme="dark"}}></div>`);
        assert.dom('[data-test-url]').hasAttribute('data-test-url', 'https://tiles.example/dark/{z}/{x}/{y}.png');
    });
});
