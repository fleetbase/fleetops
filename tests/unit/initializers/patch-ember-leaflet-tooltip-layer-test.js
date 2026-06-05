import { module, test } from 'qunit';
import TooltipLayer from 'ember-leaflet/components/tooltip-layer';
import { initialize } from 'dummy/initializers/patch-ember-leaflet-tooltip-layer';

module('Unit | Initializer | patch-ember-leaflet-tooltip-layer', function () {
    test('it tolerates tooltip setup after the parent layer has been destroyed', function (assert) {
        initialize();

        const tooltip = Object.create(TooltipLayer.prototype);
        tooltip.args = { parent: {} };
        tooltip._layer = {};

        assert.strictEqual(tooltip.addToContainer(), undefined);
        assert.strictEqual(tooltip.removeFromContainer(), undefined);
        assert.strictEqual(tooltip._removePopupListeners(), undefined);
    });

    test('it preserves tooltip setup when the parent layer is available', function (assert) {
        assert.expect(2);
        initialize();

        const tooltipLayer = {};
        const parentLayer = {
            bindTooltip(layer) {
                assert.strictEqual(layer, tooltipLayer);
            },
            unbindTooltip() {
                assert.ok(true);
            },
        };
        const tooltip = Object.create(TooltipLayer.prototype);
        tooltip.args = { parent: { _layer: parentLayer } };
        tooltip._layer = tooltipLayer;

        tooltip.addToContainer();
        tooltip.removeFromContainer();
    });
});
