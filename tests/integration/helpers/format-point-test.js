import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Helper | format-point', function (hooks) {
    setupRenderingTest(hooks);

    test('it formats a latitude, longitude pair', async function (assert) {
        this.set('point', [1.3, 103.8]);
        await render(hbs`{{format-point this.point}}`);
        assert.dom(this.element).hasText('(1.3, 103.8)');
    });

    test('it swaps GeoJSON longitude, latitude coordinates into latitude, longitude', async function (assert) {
        this.set('point', { type: 'Point', coordinates: [103.8, 1.3] });
        await render(hbs`{{format-point this.point}}`);
        assert.dom(this.element).hasText('(1.3, 103.8)');
    });

    test('anything else formats as the origin', async function (assert) {
        this.set('point', null);
        await render(hbs`{{format-point this.point}}`);
        assert.dom(this.element).hasText('(0, 0)');

        this.set('point', { coordinates: 'nope' });
        await render(hbs`{{format-point this.point}}`);
        assert.dom(this.element).hasText('(0, 0)');
    });
});
