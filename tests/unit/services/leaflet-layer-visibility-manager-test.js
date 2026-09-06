import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { settled } from '@ember/test-helpers';
import Service from '@ember/service';

const L = window.L;

/** A layer stand-in shaped like the parts of a Leaflet layer this service touches. */
function makeLayer({ el = null, style = false, opacity = false, options = {}, tooltip = null, popup = null } = {}) {
    const calls = [];
    const layer = { options, calls, on: (event, handler) => calls.push(['on', event]) && handler };

    if (el) layer._path = el;
    if (style) layer.setStyle = (next) => calls.push(['setStyle', next]);
    if (opacity) layer.setOpacity = (value) => calls.push(['setOpacity', value]);
    layer.getTooltip = () => tooltip;
    layer.getPopup = () => popup;
    layer.closeTooltip = () => calls.push(['closeTooltip']);
    layer.closePopup = () => calls.push(['closePopup']);
    layer.openTooltip = () => calls.push(['openTooltip']);
    layer.openPopup = () => calls.push(['openPopup']);

    return layer;
}

module('Unit | Service | leaflet-layer-visibility-manager', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        const test = this;
        this.element = document.createElement('div');
        this.element.style.width = '400px';
        this.element.style.height = '300px';
        document.getElementById('ember-testing').appendChild(this.element);
        this.map = L.map(this.element, {
            center: [1.3521, 103.8198],
            zoom: 12,
            zoomAnimation: false,
            fadeAnimation: false,
            markerZoomAnimation: false,
        });

        // The service takes its map from the map manager, so that is the seam for the no-map case.
        this.owner.register(
            'service:leaflet-map-manager',
            class extends Service {
                get map() {
                    return test.map;
                }
            }
        );
        this.service = this.owner.lookup('service:leaflet-layer-visibility-manager');
        this.withoutMap = () => (this.map = null);
    });

    hooks.afterEach(function () {
        this.map?.remove();
        this.element.remove();
    });

    test('a category pane is created once and reused', function (assert) {
        const first = this.service.ensurePane('drivers');
        assert.strictEqual(first.paneName, 'pane-drivers');
        assert.strictEqual(first.pane.style.zIndex, '400', 'the default stacking order');

        const second = this.service.ensurePane('drivers');
        assert.strictEqual(second.pane, first.pane, 'the pane is not made twice');

        const raised = this.service.ensurePane('alerts', { zIndex: 900 });
        assert.strictEqual(raised.pane.style.zIndex, '900');

        this.withoutMap();
        assert.strictEqual(this.service.ensurePane('drivers'), null, 'no pane without a map');
    });

    test('assigning a layer to a pane records the pane name', function (assert) {
        let redraws = 0;
        const layer = { options: { color: '#ff0000' }, redraw: () => (redraws += 1) };

        this.service.assignPane(layer, 'drivers');
        assert.strictEqual(layer.options.pane, 'pane-drivers', 'the name, not the pane object');
        assert.strictEqual(layer.options.color, '#ff0000', 'existing options survive');
        assert.strictEqual(redraws, 1);

        const bare = {};
        this.service.assignPane(bare, 'drivers');
        assert.strictEqual(bare.options.pane, 'pane-drivers', 'a layer with no options at all still gets one');

        const angry = {
            options: {},
            redraw() {
                throw new Error('not on a map');
            },
        };
        this.service.assignPane(angry, 'drivers');
        assert.strictEqual(angry.options.pane, 'pane-drivers', 'a redraw that throws does not lose the assignment');

        this.service.assignPane(null, 'drivers');
        this.withoutMap();
        this.service.assignPane(layer, 'vehicles');
        assert.strictEqual(layer.options.pane, 'pane-drivers', 'nothing is reassigned without a map');
    });

    test('a whole category is shown, hidden and toggled through its pane', function (assert) {
        assert.false(this.service.isCategoryVisible('drivers'), 'a category with no pane is not visible');
        assert.true(this.service.isCategoryHidden('drivers'));

        this.service.ensurePane('drivers');
        assert.true(this.service.isCategoryVisible('drivers'), 'a fresh pane is visible');

        this.service.hideCategory('drivers');
        assert.true(this.service.isCategoryHidden('drivers'));

        this.service.showCategory('drivers');
        assert.true(this.service.isCategoryVisible('drivers'));

        this.service.toggleCategory('drivers');
        assert.true(this.service.isCategoryHidden('drivers'), 'toggling a visible category hides it');
        this.service.toggleCategory('drivers');
        assert.true(this.service.isCategoryVisible('drivers'), 'and toggling it back shows it');

        this.service.hideCategory('nothing-here');
        assert.ok(true, 'a category with no pane is left alone');

        this.withoutMap();
        assert.false(this.service.isCategoryVisible('drivers'), 'without a map nothing is visible');
    });

    test('layers are registered by category and optionally by id', function (assert) {
        const layer = makeLayer({ el: document.createElement('div') });

        assert.strictEqual(this.service.registerLayer('drivers', null), undefined, 'a missing layer registers nothing');
        assert.strictEqual(this.service.registerLayer('drivers', layer), layer, 'the layer is handed back');

        const keyed = makeLayer({ el: document.createElement('div') });
        this.service.registerLayer('drivers', keyed, { id: 'driver_1' });
        assert.strictEqual(keyed.record_category, 'drivers');
        assert.strictEqual(keyed.record_key, 'drivers:driver_1');

        const hidden = makeLayer({ el: document.createElement('div') });
        this.service.registerLayer('vehicles', hidden, { id: 'vehicle_1', hidden: true });
        assert.strictEqual(hidden._path.style.display, 'none', 'a layer registered hidden starts hidden');
        assert.true(hidden.__hidden);

        this.withoutMap();
        assert.strictEqual(this.service.registerLayer('drivers', makeLayer()), undefined, 'nothing registers without a map');
    });

    test('showAll and hideAll reach every registered layer, softly or through the pane', async function (assert) {
        const soft = makeLayer({ style: true });
        const hard = makeLayer({ el: document.createElement('div') });
        this.service.registerLayer('drivers', soft);
        this.service.registerLayer('vehicles', hard);
        this.service.ensurePane('drivers');
        this.service.ensurePane('vehicles');

        this.service.hideAll({ soft: true });
        await settled();
        assert.deepEqual(soft.calls.at(-1), ['setStyle', { opacity: 0, fillOpacity: 0 }]);
        assert.true(soft.__hidden);
        assert.true(hard.__hidden, 'a layer with no setStyle is still marked hidden');
        assert.true(this.service.isCategoryVisible('drivers'), 'a soft hide leaves the pane alone');

        this.service.showAll({ soft: true });
        await settled();
        assert.false(soft.__hidden);
        assert.true(this.service.isCategoryVisible('drivers'));

        this.service.hideAll();
        await settled();
        assert.true(this.service.isCategoryHidden('drivers'), 'a hard hide closes the pane');
        assert.true(this.service.isCategoryHidden('vehicles'));

        this.service.showAll();
        await settled();
        assert.true(this.service.isCategoryVisible('drivers'));
        assert.false(hard.__hidden);
    });

    test('a single layer is hidden and shown softly', async function (assert) {
        const shape = makeLayer({ style: true, options: { fillOpacity: 0.4, fill: true } });

        this.service.hideLayer(shape, { soft: true });
        await settled();
        assert.deepEqual(shape.calls.at(-1), ['setStyle', { opacity: 0, fillOpacity: 0 }]);
        assert.true(shape.__hidden);

        this.service.showLayer(shape, { soft: true });
        assert.deepEqual(shape.calls.at(-1), ['setStyle', { opacity: 1, fillOpacity: 0.4 }], 'the layer keeps its own fill opacity');
        assert.false(shape.__hidden);

        const unfilled = makeLayer({ style: true, options: { fill: null } });
        this.service.showLayer(unfilled, { soft: true });
        assert.deepEqual(unfilled.calls.at(-1), ['setStyle', { opacity: 1, fillOpacity: 0 }], 'a layer with no fill is not given one');

        const marker = makeLayer({ opacity: true });
        this.service.hideLayer(marker, { soft: true });
        await settled();
        this.service.showLayer(marker, { soft: true });
        assert.deepEqual(
            marker.calls.filter(([kind]) => kind === 'setOpacity'),
            [
                ['setOpacity', 0],
                ['setOpacity', 1],
            ],
            'a layer that only knows opacity is faded instead'
        );

        const inert = makeLayer();
        this.service.hideLayer(inert, { soft: true });
        await settled();
        this.service.showLayer(inert, { soft: true });
        assert.false(inert.__hidden, 'a layer that can do neither is still tracked');
    });

    test('a hard hide takes the element down and keeps it down when the layer is re-added', async function (assert) {
        const el = document.createElement('div');
        const added = [];
        const layer = makeLayer({ el });
        layer.on = (event, handler) => {
            added.push(event);
            layer.__addHandler = handler;
        };

        this.service.hideLayer(layer);
        await settled();
        assert.strictEqual(el.style.display, 'none');
        assert.true(layer.__hidden);
        assert.deepEqual(added, ['add'], 'the service listens for the layer coming back');
        assert.true(layer.__hookedAdd);

        this.service.hideLayer(layer);
        assert.deepEqual(added, ['add'], 'and does not listen twice');

        el.style.display = '';
        layer.__addHandler();
        await settled();
        assert.strictEqual(el.style.display, 'none', 'a re-added layer is hidden again');

        layer.__hidden = false;
        el.style.display = '';
        layer.__addHandler();
        assert.strictEqual(el.style.display, '', 'unless it was shown in the meantime');

        this.service.showLayer(layer);
        assert.strictEqual(el.style.display, '');
    });

    test('a re-added layer with nothing on the page is left to itself', async function (assert) {
        const layer = { getTooltip: () => null, getPopup: () => null, on: (event, handler) => (layer.__addHandler = handler) };

        this.service.hideLayer(layer);
        await settled();
        assert.true(layer.__hidden);

        layer.__addHandler();
        await settled();
        assert.true(layer.__hidden, 'there is no element to hide, and nothing breaks');
    });

    test('the element is found through whichever handle the layer offers', async function (assert) {
        const fromGetter = document.createElement('div');
        const byGetElement = { getElement: () => fromGetter, getTooltip: () => null, getPopup: () => null };
        this.service.hideLayer(byGetElement);
        await settled();
        assert.strictEqual(fromGetter.style.display, 'none');

        const icon = document.createElement('div');
        const byIcon = { _icon: icon, getTooltip: () => null, getPopup: () => null };
        this.service.hideLayer(byIcon);
        await settled();
        assert.strictEqual(icon.style.display, 'none');

        const nowhere = { getTooltip: () => null, getPopup: () => null };
        this.service.hideLayer(nowhere);
        await settled();
        this.service.showLayer(nowhere);
        assert.true(nowhere.__hidden === false, 'a layer with nothing on the page is still tracked');
    });

    test('hiding a layer closes its tooltip and popup, and showing it puts them back', async function (assert) {
        const tooltipContainer = document.createElement('div');
        const popupContainer = document.createElement('div');
        const layer = makeLayer({
            el: document.createElement('div'),
            tooltip: { _container: tooltipContainer, isOpen: () => true, options: {} },
            popup: { _container: popupContainer, isOpen: () => true },
        });

        this.service.hideLayer(layer);
        await settled();
        assert.deepEqual(
            layer.calls.filter(([kind]) => kind.startsWith('close')),
            [['closeTooltip'], ['closePopup']]
        );
        assert.strictEqual(tooltipContainer.style.display, 'none');
        assert.strictEqual(popupContainer.style.display, 'none');
        assert.true(layer.__hadOpenTooltip);
        assert.true(layer.__hadOpenPopup);

        this.service.showLayer(layer);
        assert.strictEqual(tooltipContainer.style.display, '');
        assert.strictEqual(popupContainer.style.display, '');
        assert.deepEqual(
            layer.calls.filter(([kind]) => kind.startsWith('open')),
            [['openTooltip'], ['openPopup']]
        );
        assert.false('__hadOpenTooltip' in layer);
        assert.false('__hadOpenPopup' in layer);
    });

    test('a permanent tooltip reopens even if it was closed, and overlay errors are absorbed', async function (assert) {
        const permanent = makeLayer({ el: document.createElement('div'), tooltip: { isOpen: () => false, options: { permanent: true } } });
        this.service.hideLayer(permanent);
        await settled();
        this.service.showLayer(permanent);
        assert.deepEqual(
            permanent.calls.filter(([kind]) => kind === 'openTooltip'),
            [['openTooltip']],
            'a permanent tooltip always comes back'
        );

        const quiet = makeLayer({ el: document.createElement('div'), tooltip: { isOpen: () => false, options: {} } });
        this.service.hideLayer(quiet);
        await settled();
        this.service.showLayer(quiet);
        assert.deepEqual(
            quiet.calls.filter(([kind]) => kind === 'openTooltip'),
            [],
            'one that was closed and is not permanent stays closed'
        );

        const angry = {
            _path: document.createElement('div'),
            _tooltip: { isOpen: () => true, options: {} },
            _popup: { isOpen: () => true },
            closeTooltip() {
                throw new Error('gone');
            },
            closePopup() {
                throw new Error('gone');
            },
            openTooltip() {
                throw new Error('gone');
            },
            openPopup() {
                throw new Error('gone');
            },
        };
        this.service.hideLayer(angry);
        await settled();
        this.service.showLayer(angry);
        assert.ok(true, 'a layer whose overlays throw on both sides is survived');
    });

    test('visibility is reported from the flag, the element and the pane', function (assert) {
        assert.true(this.service.isLayerHidden(null), 'a layer that is not there counts as hidden');
        assert.true(this.service.isLayerHidden({ __hidden: true }));

        const el = document.createElement('div');
        el.style.display = 'none';
        assert.true(this.service.isLayerHidden({ _path: el }), 'the element is the second answer');
        assert.false(this.service.isLayerHidden({ _path: document.createElement('div') }));
        assert.true(this.service.isLayerVisible({ _path: document.createElement('div') }));

        const { paneName } = this.service.ensurePane('drivers');
        const inPane = { options: { pane: paneName } };
        assert.false(this.service.isLayerHidden(inPane), 'a visible pane leaves the layer visible');

        this.service.hideCategory('drivers');
        assert.true(this.service.isLayerHidden(inPane), 'a hidden pane hides everything in it');
        assert.false(this.service.isLayerHidden(inPane, { includePaneState: false }), 'unless the caller asks to ignore the pane');

        const throughRenderer = { options: { renderer: { options: { pane: paneName } } } };
        assert.true(this.service.isLayerHidden(throughRenderer), 'the pane can come from the renderer');

        assert.false(this.service.isLayerHidden({ options: {} }), 'a layer with no pane falls back to the overlay pane');
        assert.false(this.service.isLayerHidden({ options: { pane: 'pane-that-was-never-made' } }), 'a layer naming a pane that does not exist is not hidden by it');

        this.withoutMap();
        assert.false(this.service.isLayerHidden({ options: { pane: paneName } }), 'with no map there is no pane state to consult');
    });

    test('model helpers read the layer off the model', async function (assert) {
        const el = document.createElement('div');
        const model = { leafletLayer: makeLayer({ el }) };

        this.service.hideModelLayer(model);
        await settled();
        assert.strictEqual(el.style.display, 'none');
        assert.true(this.service.isModelLayerHidden(model));
        assert.false(this.service.isModelLayerVisible(model));

        this.service.showModelLayer(model);
        assert.strictEqual(el.style.display, '');
        assert.true(this.service.isModelLayerVisible(model));

        this.service.showModelLayer(null);
        this.service.hideModelLayer(null);
        assert.true(this.service.isModelLayerHidden(null), 'a model with no layer counts as hidden');
        assert.false(this.service.isModelLayerVisible(undefined));
    });

    test('nothing happens to a layer while there is no map', function (assert) {
        this.withoutMap();
        const el = document.createElement('div');
        const layer = makeLayer({ el });

        this.service.hideLayer(layer);
        this.service.showLayer(layer);
        this.service.hideLayer(null);
        this.service.showLayer(null);

        assert.strictEqual(el.style.display, '', 'the element is untouched');
        assert.strictEqual(layer.__hidden, undefined);
    });
});
