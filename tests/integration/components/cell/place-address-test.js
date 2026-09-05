import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | cell/place-address', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the row as a place address', async function (assert) {
        this.set('place', { name: 'Depot', street1: '1 Main Street', city: 'Springfield', country: 'US' });

        await render(hbs`<Cell::PlaceAddress @row={{this.place}} />`);

        assert.dom(this.element).includesText('1 Main Street');
        assert.dom(this.element).includesText('Springfield');
    });
});
