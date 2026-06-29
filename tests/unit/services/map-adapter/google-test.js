import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

function installGoogleMapsStub(context) {
    class StubMap {
        constructor(_element, options = {}) {
            this.options = options;
            this.mapTypeIds = [options.mapTypeId];
            this.setOptionsCalls = [];
            context.map = this;
        }

        setMapTypeId(mapTypeId) {
            this.mapTypeIds.push(mapTypeId);
        }

        setOptions(options = {}) {
            this.setOptionsCalls.push(options);
            this.options = {
                ...this.options,
                ...options,
            };
        }
    }

    class StubTrafficLayer {
        constructor() {
            this.setMapCalls = [];
            context.trafficLayers.push(this);
        }

        setMap(map) {
            this.setMapCalls.push(map);
        }
    }

    class StubTransitLayer {
        constructor() {
            this.setMapCalls = [];
            context.transitLayers.push(this);
        }

        setMap(map) {
            this.setMapCalls.push(map);
        }
    }

    class StubDrawingManager {
        addListener() {}
        setMap() {}
    }

    const googleStub = {
        maps: {
            MapTypeId: {
                ROADMAP: 'roadmap',
            },
            drawing: {
                OverlayType: {
                    POLYGON: 'polygon',
                    CIRCLE: 'circle',
                    RECTANGLE: 'rectangle',
                    POLYLINE: 'polyline',
                    MARKER: 'marker',
                },
            },
            event: {
                trigger() {},
            },
            importLibrary(name) {
                if (name === 'maps') {
                    return Promise.resolve({
                        Map: StubMap,
                        TrafficLayer: StubTrafficLayer,
                        TransitLayer: StubTransitLayer,
                    });
                }

                if (name === 'marker') {
                    return Promise.resolve({
                        AdvancedMarkerElement: class AdvancedMarkerElement {},
                    });
                }

                if (name === 'drawing') {
                    return Promise.resolve({
                        DrawingManager: StubDrawingManager,
                    });
                }

                return Promise.resolve({});
            },
        },
    };

    window.google = googleStub;
    globalThis.google = googleStub;
}

module('Unit | Service | map-adapter/google', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.originalGoogle = window.google;
        this.originalGlobalGoogle = globalThis.google;
        this.map = null;
        this.trafficLayers = [];
        this.transitLayers = [];
        installGoogleMapsStub(this);
    });

    hooks.afterEach(function () {
        const service = this.owner.lookup('service:map-adapter/google');
        service.destroyMap();
        window.google = this.originalGoogle;
        globalThis.google = this.originalGlobalGoogle;
    });

    test('it applies google traffic and transit layers during initialization', async function (assert) {
        const service = this.owner.lookup('service:map-adapter/google');

        await service.initializeMap(document.createElement('div'), {
            mapTypeId: 'satellite',
            showTrafficLayer: true,
            showTransitLayer: true,
        });

        assert.strictEqual(this.map.mapTypeIds[this.map.mapTypeIds.length - 1], 'satellite');
        assert.strictEqual(this.trafficLayers.length, 1);
        assert.deepEqual(this.trafficLayers[0].setMapCalls, [this.map]);
        assert.strictEqual(this.transitLayers.length, 1);
        assert.deepEqual(this.transitLayers[0].setMapCalls, [this.map]);
    });

    test('it removes google traffic and transit layers when view settings are disabled', async function (assert) {
        const service = this.owner.lookup('service:map-adapter/google');

        await service.initializeMap(document.createElement('div'), {
            mapTypeId: 'roadmap',
            showTrafficLayer: true,
            showTransitLayer: true,
        });

        const trafficLayer = this.trafficLayers[0];
        const transitLayer = this.transitLayers[0];

        await service.applyViewSettings({
            mapTypeId: 'terrain',
            showTrafficLayer: false,
            showTransitLayer: false,
        });

        assert.strictEqual(this.map.mapTypeIds[this.map.mapTypeIds.length - 1], 'terrain');
        assert.deepEqual(trafficLayer.setMapCalls, [this.map, null]);
        assert.deepEqual(transitLayer.setMapCalls, [this.map, null]);
    });

    test('it does not include the hidden transit style when transit layer is enabled', async function (assert) {
        const service = this.owner.lookup('service:map-adapter/google');

        await service.initializeMap(document.createElement('div'), {
            showTransitLayer: true,
        });

        const transitStyle = this.map.options.styles.find((style) => style.featureType === 'transit');

        assert.strictEqual(transitStyle, undefined);
    });
});
