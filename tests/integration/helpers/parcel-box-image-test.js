import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Helper | parcel-box-image', function (hooks) {
    setupRenderingTest(hooks);

    test('it returns the box image for each parcel size', async function (assert) {
        for (const size of ['small', 'medium', 'large', 'x-large']) {
            this.set('size', size);
            await render(hbs`{{parcel-box-image this.size}}`);
            assert.dom().hasText(`/engines-dist/images/boxes/${size}.png`);
        }
    });

    test('it falls back to the medium box for an unknown size', async function (assert) {
        this.set('size', 'oversized');
        await render(hbs`{{parcel-box-image this.size}}`);
        assert.dom().hasText('/engines-dist/images/boxes/medium.png');
    });
});
