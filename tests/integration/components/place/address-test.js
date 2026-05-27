import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | place/address', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the place title by default', async function (assert) {
        this.set('place', {
            name: 'North Dock',
            street1: '100 Harbor Road',
            city: 'Singapore',
            country_name: 'Singapore',
        });

        await render(hbs`<Place::Address @place={{this.place}} />`);

        assert.dom('address').containsText('North Dock');
        assert.dom('address').containsText('100 Harbor Road');
    });

    test('it hides the place title when showTitle is false', async function (assert) {
        this.set('place', {
            name: 'North Dock',
            street1: '100 Harbor Road',
            city: 'Singapore',
            country_name: 'Singapore',
        });

        await render(hbs`<Place::Address @place={{this.place}} @showTitle={{false}} />`);

        assert.dom('address').doesNotContainText('North Dock');
        assert.dom('address').containsText('100 Harbor Road');
    });
});
