import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, settled } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

module('Integration | Helper | is-model-leaflet-layer-hidden', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const asked = (this.asked = []);
        this.owner.register(
            'service:leaflet-layer-visibility-manager',
            class extends Service {
                isModelLayerHidden(model) {
                    asked.push(model);
                    return model?.hidden === true;
                }
            }
        );
    });

    test('it asks the visibility manager about the model layer', async function (assert) {
        const hiddenModel = { hidden: true };
        const visibleModel = { hidden: false };

        this.set('model', hiddenModel);
        await render(hbs`{{if (is-model-leaflet-layer-hidden this.model) "hidden" "visible"}}`);
        assert.dom(this.element).hasText('hidden');

        this.set('model', visibleModel);
        await settled();
        assert.dom(this.element).hasText('visible');

        assert.deepEqual(this.asked, [hiddenModel, visibleModel], 'the helper recomputes once per model');
    });
});
