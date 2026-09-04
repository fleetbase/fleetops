import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { waitUntil } from '@ember/test-helpers';

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

    // The adapter reaches the Google namespace through `window.google` inside each method, so a
    // per-test stub is enough — there is no module-scope capture to work around.
    class StubLatLng {
        constructor(lat, lng) {
            this._lat = lat;
            this._lng = lng;
        }

        lat() {
            return this._lat;
        }

        lng() {
            return this._lng;
        }
    }

    class StubLatLngBounds {
        constructor(sw = null, ne = null) {
            this.extended = [];
            this._sw = sw;
            this._ne = ne;
        }

        extend(point) {
            this.extended.push(point);
            return this;
        }

        getSouthWest() {
            return this._sw ?? new StubLatLng(0, 0);
        }

        getNorthEast() {
            return this._ne ?? new StubLatLng(0, 0);
        }
    }

    class StubMarker {
        constructor(options = {}) {
            Object.assign(this, options);
            this.listeners = [];
            this.setMapCalls = [];
            this.setPositionCalls = [];
            context.classicMarkers.push(this);
        }

        addListener(event, handler) {
            this.listeners.push([event, handler]);
        }

        setMap(map) {
            this.setMapCalls.push(map);
        }

        setPosition(position) {
            this.setPositionCalls.push(position);
            this.position = position;
        }
    }

    class StubAdvancedMarker {
        constructor(options = {}) {
            Object.assign(this, options);
            this.listeners = [];
            context.advancedMarkers.push(this);
        }

        addListener(event, handler) {
            this.listeners.push([event, handler]);
        }
    }

    const googleStub = {
        maps: {
            LatLng: StubLatLng,
            LatLngBounds: StubLatLngBounds,
            Marker: StubMarker,
            Size: class StubSize {
                constructor(width, height) {
                    this.width = width;
                    this.height = height;
                }
            },
            Point: class StubPoint {
                constructor(x, y) {
                    this.x = x;
                    this.y = y;
                }
            },
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
                addListenerOnce(target, eventName, handler) {
                    context.onceListeners.push([eventName, handler]);
                },
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
                    return Promise.resolve({ AdvancedMarkerElement: StubAdvancedMarker });
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
        this.classicMarkers = [];
        this.advancedMarkers = [];
        this.onceListeners = [];
        installGoogleMapsStub(this);
        this.service = this.owner.lookup('service:map-adapter/google');

        // A map stand-in for the viewport and marker work, which does not need a real Google map.
        this.useMap = (overrides = {}) => {
            const calls = [];
            const map = {
                calls,
                setCenter: (center) => calls.push(['setCenter', center]),
                setZoom: (zoom) => calls.push(['setZoom', zoom]),
                panTo: (center) => calls.push(['panTo', center]),
                fitBounds: (bounds, padding) => calls.push(['fitBounds', bounds, padding]),
                getZoom: () => 12,
                ...overrides,
            };
            this.service._map = map;
            return map;
        };
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

    test('every viewport call is a no-op before there is a map', function (assert) {
        assert.strictEqual(this.service.setCenter(1, 2, 3), undefined);
        assert.strictEqual(this.service.flyTo(1, 2, 3), undefined);
        assert.strictEqual(this.service.fitBounds([[1, 2]]), undefined);
        assert.strictEqual(this.service.panTo(1, 2), undefined);
        assert.strictEqual(this.service.zoomIn(), undefined);
        assert.strictEqual(this.service.zoomOut(), undefined);
        assert.strictEqual(this.service.getZoom(), 0, 'no map reports zoom zero');
        assert.deepEqual(this.service.getCenter(), { lat: 0, lng: 0 });
        assert.deepEqual(this.service.getBounds(), [
            [0, 0],
            [0, 0],
        ]);
    });

    test('the viewport is centred, panned and zoomed through the Google map', function (assert) {
        const map = this.useMap();

        this.service.setCenter(1.3521, 103.8198, 10);
        assert.deepEqual(map.calls, [
            ['setCenter', { lat: 1.3521, lng: 103.8198 }],
            ['setZoom', 10],
        ]);

        map.calls.length = 0;
        this.service.setCenter(40.7128, -74.006);
        assert.deepEqual(map.calls, [['setCenter', { lat: 40.7128, lng: -74.006 }]], 'no zoom is sent when none was asked for');

        map.calls.length = 0;
        this.service.panTo(48.8566, 2.3522);
        assert.deepEqual(map.calls, [['panTo', { lat: 48.8566, lng: 2.3522 }]]);

        map.calls.length = 0;
        this.service.zoomIn();
        this.service.zoomOut();
        assert.deepEqual(map.calls, [
            ['setZoom', 13],
            ['setZoom', 11],
        ]);
    });

    test('flyTo pans immediately and zooms once the pan has started', async function (assert) {
        const map = this.useMap();

        this.service.flyTo(1.3521, 103.8198, 9, { delay: 0 });
        assert.deepEqual(map.calls, [['panTo', { lat: 1.3521, lng: 103.8198 }]], 'the pan is immediate');

        await waitUntil(() => map.calls.some(([kind]) => kind === 'setZoom'));
        assert.deepEqual(map.calls.at(-1), ['setZoom', 9], 'the zoom follows');

        map.calls.length = 0;
        this.service.flyTo(1.4, 103.9);
        assert.deepEqual(map.calls, [['panTo', { lat: 1.4, lng: 103.9 }]], 'with no zoom asked for, nothing is scheduled');
    });

    test('the current centre and bounds are read back off the map', function (assert) {
        this.useMap({
            getCenter: () => ({ lat: () => 1.3521, lng: () => 103.8198 }),
            getBounds: () => ({
                getSouthWest: () => ({ lat: () => 1.2, lng: () => 103.6 }),
                getNorthEast: () => ({ lat: () => 1.5, lng: () => 104.0 }),
            }),
        });

        assert.deepEqual(this.service.getCenter(), { lat: 1.3521, lng: 103.8198 });
        assert.strictEqual(this.service.getZoom(), 12);
        assert.deepEqual(this.service.getBounds(), [
            [1.2, 103.6],
            [1.5, 104.0],
        ]);

        this.useMap({ getCenter: () => null, getBounds: () => null });
        assert.deepEqual(this.service.getCenter(), { lat: 0, lng: 0 }, 'a map that answers with nothing reports the origin');
        assert.deepEqual(this.service.getBounds(), [
            [0, 0],
            [0, 0],
        ]);
    });

    test('fitBounds accepts a bounds object, a corner pair, or loose points', function (assert) {
        const map = this.useMap();

        this.service.fitBounds(null);
        assert.deepEqual(map.calls, [], 'nothing to fit');

        const bounds = new window.google.maps.LatLngBounds();
        this.service.fitBounds(bounds);
        assert.strictEqual(map.calls.at(-1)[1], bounds, 'a bounds object is passed straight through');

        this.service.fitBounds([
            [1.2, 103.6],
            [1.5, 104.0],
        ]);
        const fromCorners = map.calls.at(-1)[1];
        assert.strictEqual(fromCorners.getSouthWest().lat(), 1.2, 'a corner pair becomes a bounds');
        assert.strictEqual(fromCorners.getNorthEast().lng(), 104.0);

        this.service.fitBounds([
            { lat: 1.2, lng: 103.6 },
            { lat: 1.5, lng: 104.0 },
        ]);
        assert.deepEqual(
            map.calls.at(-1)[1].extended,
            [
                { lat: 1.2, lng: 103.6 },
                { lat: 1.5, lng: 104.0 },
            ],
            'loose points are collected and extended in'
        );

        this.service.fitBounds([[[1.2, 103.6], [[1.5, 104.0]]]]);
        assert.deepEqual(
            map.calls.at(-1)[1].extended,
            [
                { lat: 1.2, lng: 103.6 },
                { lat: 1.5, lng: 104.0 },
            ],
            'however deeply they are nested'
        );

        map.calls.length = 0;
        this.service.fitBounds(['not a point']);
        assert.deepEqual(map.calls, [], 'input with no points in it fits nothing');
    });

    test('fitBounds honours padding and holds the zoom below a ceiling', function (assert) {
        const map = this.useMap();

        this.service.fitBounds(
            [
                [1.2, 103.6],
                [1.5, 104.0],
            ],
            { paddingBottomRight: [300, 40] }
        );
        assert.deepEqual(map.calls.at(-1)[2], { right: 300, bottom: 40 });

        this.service.fitBounds(
            [
                [1.2, 103.6],
                [1.5, 104.0],
            ],
            { padding: [10] }
        );
        assert.deepEqual(map.calls.at(-1)[2], { right: 10, bottom: 0 }, 'a one-sided padding defaults the other');

        map.calls.length = 0;
        this.service.fitBounds(
            [
                [1.2, 103.6],
                [1.5, 104.0],
            ],
            { maxZoom: 10 }
        );
        assert.strictEqual(this.onceListeners.at(-1)[0], 'idle', 'the ceiling is applied once the map settles');

        this.onceListeners.at(-1)[1]();
        assert.deepEqual(map.calls.at(-1), ['setZoom', 10], 'a zoom past the ceiling is pulled back');

        this.useMap({ getZoom: () => 8 });
        this.service.fitBounds(
            [
                [1.2, 103.6],
                [1.5, 104.0],
            ],
            { maxZoom: 10 }
        );
        const settledMap = this.service._map;
        settledMap.calls.length = 0;
        this.onceListeners.at(-1)[1]();
        assert.deepEqual(
            settledMap.calls.filter(([kind]) => kind === 'setZoom'),
            [],
            'a zoom already inside the ceiling is left alone'
        );
    });

    test('a classic marker is built when the map has no advanced-marker support', async function (assert) {
        assert.strictEqual(await this.service.addMarker('marker_1', 1.3, 103.8), null, 'nothing is placed without a map');

        this.useMap();
        this.service._supportsAdvancedMarkers = false;

        const clicks = [];
        const marker = await this.service.addMarker('marker_1', 1.3, 103.8, {
            title: 'Depot',
            zIndexOffset: 400,
            onClick: () => clicks.push('click'),
            onRightClick: () => clicks.push('rightclick'),
        });

        assert.strictEqual(this.service._markers.get('marker_1'), marker);
        assert.deepEqual(marker.position, { lat: 1.3, lng: 103.8 });
        assert.strictEqual(marker.title, 'Depot');
        assert.strictEqual(marker.zIndex, 400);
        assert.deepEqual(
            marker.listeners.map(([event]) => event),
            ['click', 'rightclick']
        );

        marker.listeners[0][1]();
        marker.listeners[1][1]({ latLng: { lat: () => 1.3, lng: () => 103.8 } });
        assert.deepEqual(clicks, ['click', 'rightclick'], 'both handlers are wired');
    });

    test('a classic marker takes a waypoint badge or an icon url', async function (assert) {
        this.useMap();
        this.service._supportsAdvancedMarkers = false;

        const badged = await this.service.addMarker('marker_badge', 1.3, 103.8, { waypointLabel: 'P', waypointColor: '#22c55e' });
        assert.strictEqual(badged.zIndex, 100000, 'a badge floats above everything else');
        assert.strictEqual(badged.icon.scaledSize.width, 34);
        assert.strictEqual(badged.icon.anchor.x, 17);
        assert.false(badged.optimized);

        const iconned = await this.service.addMarker('marker_icon', 1.3, 103.8, { iconUrl: '/assets/pin.png', iconSize: [48, 48] });
        assert.strictEqual(iconned.icon.url, '/assets/pin.png');
        assert.strictEqual(iconned.icon.scaledSize.width, 48);
        assert.strictEqual(iconned.icon.anchor.x, 24, 'the anchor sits at the centre');
        assert.strictEqual(iconned.zIndex, 0);

        const defaulted = await this.service.addMarker('marker_default', 1.3, 103.8, { iconUrl: '/assets/pin.png', tooltip: 'Depot' });
        assert.strictEqual(defaulted.icon.scaledSize.width, 24, 'the default icon size');
        assert.strictEqual(defaulted.title, 'Depot', 'a tooltip stands in for a missing title');
    });

    test('an advanced marker renders its own content element', async function (assert) {
        this.useMap();
        this.service._supportsAdvancedMarkers = true;

        const given = document.createElement('span');
        const withContent = await this.service.addMarker('marker_content', 1.3, 103.8, { content: given, title: 'Depot' });
        assert.strictEqual(withContent.content, given, 'a caller-built element is used as-is');
        assert.strictEqual(given.getAttribute('title'), 'Depot', 'and is labelled');
        assert.strictEqual(withContent.map, this.service._map);
        assert.deepEqual(withContent.position, { lat: 1.3, lng: 103.8 });

        const fromIcon = await this.service.addMarker('marker_icon', 1.3, 103.8, {
            iconUrl: '/assets/pin.png',
            iconSize: [48, 48],
            alt: 'A pin',
            rotationAngle: 90,
            zIndexOffset: 7,
        });
        assert.strictEqual(fromIcon.content.className, 'fleetops-map-marker');
        const img = fromIcon.content.querySelector('img');
        assert.strictEqual(img.getAttribute('src'), '/assets/pin.png');
        assert.strictEqual(img.width, 48);
        assert.strictEqual(img.alt, 'A pin');
        assert.strictEqual(fromIcon.content.style.transform, 'rotate(90deg)', 'the rotation is applied to the wrapper');
        assert.strictEqual(fromIcon.zIndex, 7);

        const sizeless = await this.service.addMarker('marker_sizeless', 1.3, 103.8, { iconUrl: '/assets/pin.png' });
        assert.strictEqual(sizeless.content.querySelector('img').width, 24, 'the default size');

        const bare = await this.service.addMarker('marker_bare', 1.3, 103.8);
        assert.strictEqual(bare.content, null, 'a marker with nothing to show has no content');
        assert.strictEqual(bare.title, '');
    });

    test('an advanced marker wires its click, right-click and tooltip', async function (assert) {
        this.useMap();
        this.service._supportsAdvancedMarkers = true;
        const seen = [];

        const marker = await this.service.addMarker('marker_1', 1.3, 103.8, {
            iconUrl: '/assets/pin.png',
            onClick: () => seen.push('click'),
            onRightClick: (event) => seen.push(['rightclick', event.type]),
            tooltip: 'Depot',
        });

        assert.deepEqual(
            marker.listeners.map(([event]) => event),
            ['click', 'rightclick']
        );
        marker.listeners[0][1]();
        marker.listeners[1][1]({ latLng: { lat: () => 1.3, lng: () => 103.8 } });
        assert.deepEqual(seen, ['click', ['rightclick', 'rightclick']]);
        assert.strictEqual(typeof marker.__tooltipCleanup, 'function', 'the tooltip leaves something to clean up');
    });

    test('a marker is moved outright, or animated to its new position', async function (assert) {
        this.useMap();
        this.service._supportsAdvancedMarkers = false;
        const marker = await this.service.addMarker('marker_1', 1.3, 103.8);

        this.service.updateMarkerPosition('unknown', 1.4, 103.9);
        this.service.updateMarkerPosition('marker_1', 'not a number', 103.9);
        assert.deepEqual(marker.setPositionCalls, [], 'an unknown marker and an unusable point are both ignored');

        this.service.updateMarkerPosition('marker_1', 1.4, 103.9, false);
        assert.deepEqual(marker.setPositionCalls.at(-1), { lat: 1.4, lng: 103.9 }, 'an un-animated move lands at once');

        this.service.updateMarkerPosition('marker_1', 1.5, 104.0, true, 0);
        assert.deepEqual(marker.setPositionCalls.at(-1), { lat: 1.5, lng: 104.0 }, 'so does one with no duration');

        this.service.updateMarkerPosition('marker_1', 1.6, 104.1, true, 20);
        await waitUntil(() => marker.setPositionCalls.at(-1)?.lat === 1.6, { timeout: 2000 });
        assert.strictEqual(marker.setPositionCalls.at(-1).lng, 104.1, 'an animated move eases to its target');
        assert.strictEqual(this.service._animations.size, 0, 'and cleans up after itself');
    });

    test('a second move cancels the animation still running, and a placeless marker jumps', async function (assert) {
        this.useMap();
        this.service._supportsAdvancedMarkers = false;
        const marker = await this.service.addMarker('marker_1', 1.3, 103.8);

        this.service.updateMarkerPosition('marker_1', 5, 5, true, 3000);
        assert.strictEqual(this.service._animations.size, 1, 'the long move is running');

        this.service.updateMarkerPosition('marker_1', 1.4, 103.9, false);
        assert.strictEqual(this.service._animations.size, 0, 'starting another move cancels it');
        assert.deepEqual(marker.setPositionCalls.at(-1), { lat: 1.4, lng: 103.9 });

        marker.position = null;
        this.service.updateMarkerPosition('marker_1', 1.7, 104.2, true, 500);
        assert.deepEqual(marker.setPositionCalls.at(-1), { lat: 1.7, lng: 104.2 }, 'a marker with no position to ease from simply jumps');
    });

    test('a marker position is read from either shape, and a refusal is survived', async function (assert) {
        this.useMap();
        this.service._supportsAdvancedMarkers = false;
        const marker = await this.service.addMarker('marker_1', 1.3, 103.8);

        marker.position = { lat: () => 1.3, lng: () => 103.8 };
        this.service.updateMarkerPosition('marker_1', 1.35, 103.85, true, 20);
        await waitUntil(() => marker.setPositionCalls.at(-1)?.lat === 1.35, { timeout: 2000 });
        assert.ok(true, 'a position exposed as functions is read the same way');

        marker.position = { lat: () => NaN, lng: () => NaN };
        this.service.updateMarkerPosition('marker_1', 1.45, 103.95, true, 500);
        assert.deepEqual(marker.setPositionCalls.at(-1), { lat: 1.45, lng: 103.95 }, 'an unreadable position falls back to a jump');

        marker.setPosition = () => {
            throw new Error('detached');
        };
        this.service.updateMarkerPosition('marker_1', 1.55, 104.05, false);
        assert.ok(true, 'a marker that refuses the move does not take the caller down');
    });

    test('marker rotation only lands on a marker that has content', async function (assert) {
        this.useMap();
        this.service._supportsAdvancedMarkers = true;
        const marker = await this.service.addMarker('marker_1', 1.3, 103.8, { iconUrl: '/assets/pin.png' });

        this.service.setMarkerRotation('unknown', 90);
        this.service.setMarkerRotation('marker_1', 45);

        assert.strictEqual(marker.content.style.transform, 'rotate(45deg)');
        assert.strictEqual(marker.content.style.transformOrigin, 'center center');

        const bare = await this.service.addMarker('marker_bare', 1.3, 103.8);
        this.service.setMarkerRotation('marker_bare', 45);
        assert.strictEqual(bare.content, null, 'a marker with nothing to rotate is left alone');
    });

    test('removing a marker cancels its animation and detaches it', async function (assert) {
        this.useMap();
        this.service._supportsAdvancedMarkers = false;
        const marker = await this.service.addMarker('marker_1', 1.3, 103.8, { tooltip: 'Depot' });

        let cleaned = 0;
        marker.__tooltipCleanup = () => (cleaned += 1);
        this.service.updateMarkerPosition('marker_1', 5, 5, true, 3000);

        this.service.removeMarker('marker_1');
        assert.strictEqual(this.service._animations.size, 0, 'the running animation is cancelled');
        assert.strictEqual(cleaned, 1, 'the tooltip is cleaned up');
        assert.deepEqual(marker.setMapCalls, [null], 'and the marker leaves the map');
        assert.strictEqual(this.service._markers.size, 0);

        this.service.removeMarker('marker_1');
        assert.strictEqual(this.service._markers.size, 0, 'removing an unknown marker is harmless');

        this.service._supportsAdvancedMarkers = true;
        const advanced = await this.service.addMarker('marker_2', 1.3, 103.8);
        this.service.removeMarker('marker_2');
        assert.strictEqual(advanced.map, null, 'an advanced marker is detached by clearing its map');
    });
});
