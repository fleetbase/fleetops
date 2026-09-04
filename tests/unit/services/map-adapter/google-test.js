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
        constructor(options = {}) {
            this.options = options;
            this.listeners = [];
            this.modes = [];
            context.drawingManagers.push(this);
        }

        addListener(event, handler) {
            this.listeners.push([event, handler]);
        }

        fire(event, payload) {
            this.listeners.filter(([name]) => name === event).forEach(([, handler]) => handler(payload));
        }

        setDrawingMode(mode) {
            this.modes.push(mode);
        }

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

        fire(event, payload) {
            this.listeners.filter(([name]) => name === event).forEach(([, handler]) => handler(payload));
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

    /** Google's Polygon, Polyline and Circle share the surface the adapter uses. */
    class StubOverlay {
        constructor(options = {}) {
            Object.assign(this, options);
            this.listeners = [];
            this.setMapCalls = [];
            this.setOptionsCalls = [];
            context.overlays.push(this);
        }

        addListener(event, handler) {
            this.listeners.push([event, handler]);
        }

        fire(event, payload) {
            this.listeners.filter(([name]) => name === event).forEach(([, handler]) => handler(payload));
        }

        setMap(map) {
            this.setMapCalls.push(map);
            this.map = map;
        }

        setOptions(options) {
            this.setOptionsCalls.push(options);
        }

        get(key) {
            return this[key];
        }

        setEditable(editable) {
            this.editable = editable;
        }

        setPath(path) {
            this.restoredPath = path;
        }

        setBounds(bounds) {
            this.restoredBounds = bounds;
        }

        setCenter(center) {
            this.restoredCenter = center;
        }

        setRadius(radius) {
            this.restoredRadius = radius;
        }
    }

    class StubInfoWindow {
        constructor(options = {}) {
            Object.assign(this, options);
            this.openCalls = [];
            this.closeCalls = 0;
            context.infoWindows.push(this);
        }

        open(options) {
            this.openCalls.push(options);
        }

        close() {
            this.closeCalls += 1;
        }
    }

    class StubDataLayer {
        constructor(options = {}) {
            Object.assign(this, options);
            this.geoJson = [];
            this.styles = [];
            this.setMapCalls = [];
            context.dataLayers.push(this);
        }

        addGeoJson(geojson) {
            this.geoJson.push(geojson);
        }

        setStyle(style) {
            this.styles.push(style);
        }

        setMap(map) {
            this.setMapCalls.push(map);
        }
    }

    /**
     * The adapter builds its polygon labels as an OverlayView subclass, assigning its own
     * `onAdd`/`draw`/`onRemove`. The stub supplies the two hooks those bodies call back into.
     */
    class StubOverlayView {
        constructor() {
            this.setMapCalls = [];
            context.overlayViews.push(this);
        }

        setMap(map) {
            this.setMapCalls.push(map);
            this.map = map;
            if (map) {
                this.onAdd?.();
                this.draw?.();
            } else {
                this.onRemove?.();
            }
        }

        getPanes() {
            return context.panes;
        }

        getProjection() {
            return context.projection;
        }
    }

    const googleStub = {
        maps: {
            OverlayView: StubOverlayView,
            InfoWindow: StubInfoWindow,
            Data: StubDataLayer,
            ImageMapType: class StubImageMapType {
                constructor(options = {}) {
                    Object.assign(this, options);
                    context.imageMapTypes.push(this);
                }
            },
            geometry: {
                spherical: {
                    computeDistanceBetween(from, to) {
                        // Rough metres-per-degree, enough to assert the arguments arrived intact.
                        const dLat = to.lat() - from.lat();
                        const dLng = to.lng() - from.lng();
                        return Math.sqrt(dLat * dLat + dLng * dLng) * 111320;
                    },
                },
            },
            Polygon: StubOverlay,
            Polyline: StubOverlay,
            Circle: StubOverlay,
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
                    const entry = [eventName, handler];
                    context.onceListeners.push(entry);
                    return entry;
                },
                removeListener(listener) {
                    context.removedListeners.push(listener);
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
        const test = this;
        this.originalGoogle = window.google;
        this.originalGlobalGoogle = globalThis.google;
        this.map = null;
        this.trafficLayers = [];
        this.transitLayers = [];
        this.classicMarkers = [];
        this.advancedMarkers = [];
        this.onceListeners = [];
        this.overlays = [];
        this.infoWindows = [];
        this.dataLayers = [];
        this.imageMapTypes = [];
        this.removedListeners = [];
        this.overlayViews = [];
        this.drawingManagers = [];
        this.panes = { overlayMouseTarget: document.createElement('div') };
        this.projection = { fromLatLngToDivPixel: () => ({ x: 40, y: 60 }) };
        installGoogleMapsStub(this);
        this.service = this.owner.lookup('service:map-adapter/google');

        // The draw toolbar and the label overlays append into the map's own element.
        this.mapDiv = document.createElement('div');
        document.getElementById('ember-testing').appendChild(this.mapDiv);

        // A map stand-in for the viewport and marker work, which does not need a real Google map.
        this.useMap = (overrides = {}) => {
            const calls = [];
            const map = {
                calls,
                listeners: [],
                setCenter: (center) => calls.push(['setCenter', center]),
                setZoom: (zoom) => calls.push(['setZoom', zoom]),
                panTo: (center) => calls.push(['panTo', center]),
                fitBounds: (bounds, padding) => calls.push(['fitBounds', bounds, padding]),
                getZoom: () => 12,
                getDiv: () => test.mapDiv,
                addListener: (event, handler) => {
                    const entry = { event, handler };
                    calls.push(['addListener', event]);
                    map.listeners.push(entry);
                    return entry;
                },
                overlayMapTypes: {
                    cleared: 0,
                    inserted: [],
                    clear() {
                        this.cleared += 1;
                    },
                    insertAt(index, layer) {
                        this.inserted.push([index, layer]);
                    },
                },
                ...overrides,
            };
            this.service._map = map;
            return map;
        };
    });

    hooks.afterEach(function () {
        const service = this.owner.lookup('service:map-adapter/google');
        service.destroyMap();
        this.mapDiv?.remove();
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

    test('overlays are drawn with Fleet-Ops defaults and registered by id', function (assert) {
        const ring = [
            [1.3, 103.8],
            [1.4, 103.9],
            [1.5, 103.7],
        ];

        assert.strictEqual(this.service.addPolygon('zone_1', ring), null, 'nothing is drawn without a map');
        assert.strictEqual(this.service.addPolyline('line_1', ring), null);
        assert.strictEqual(this.service.addCircle('circle_1', 1.3, 103.8, 100), null);

        this.useMap();

        const polygon = this.service.addPolygon('zone_1', ring);
        assert.strictEqual(polygon.strokeColor, '#3388ff', 'the default stroke');
        assert.strictEqual(polygon.fillColor, '#3388ff', 'the fill follows the stroke');
        assert.strictEqual(polygon.fillOpacity, 0.2);
        assert.strictEqual(polygon.strokeWeight, 3);
        assert.false(polygon.editable);
        assert.true(polygon.clickable);
        assert.strictEqual(polygon.zIndex, 1);
        assert.strictEqual(this.service._overlays.get('zone_1'), polygon);

        const styled = this.service.addPolygon('zone_2', ring, {
            color: '#ff0000',
            fillColor: '#00ff00',
            fillOpacity: 0.6,
            weight: 8,
            editable: true,
            clickable: false,
            zIndex: 9,
        });
        assert.strictEqual(styled.strokeColor, '#ff0000');
        assert.strictEqual(styled.fillColor, '#00ff00');
        assert.strictEqual(styled.zIndex, 9);
        assert.true(styled.editable);
        assert.false(styled.clickable);

        const polyline = this.service.addPolyline('line_1', ring, { color: '#123456', weight: 5, opacity: 0.5 });
        assert.strictEqual(polyline.strokeColor, '#123456');
        assert.strictEqual(polyline.strokeOpacity, 0.5);

        const circle = this.service.addCircle('circle_1', 1.3, 103.8, 250, { color: '#abcdef' });
        assert.strictEqual(circle.radius, 250);
        assert.deepEqual(circle.center, { lat: 1.3, lng: 103.8 });
        assert.strictEqual(circle.fillColor, '#abcdef', 'the fill follows the given stroke');
    });

    test('a shape with too few usable points is not drawn', function (assert) {
        this.useMap();

        assert.strictEqual(this.service.addPolygon('zone_1', []), null, 'an empty ring');
        assert.strictEqual(this.service.addPolygon('zone_1', 'not a ring'), null, 'something that is not a list');
        assert.strictEqual(
            this.service.addPolygon('zone_1', [
                [1.3, 103.8],
                [1.4, 103.9],
            ]),
            null,
            'a ring of two points'
        );
        assert.strictEqual(this.service.addPolyline('line_1', [[1.3, 103.8]]), null, 'a line of one point');
        assert.strictEqual(this.service.addPolyline('line_1', 'not a line'), null);
    });

    test('a polygon accepts one ring or many, however the points are shaped', function (assert) {
        this.useMap();

        const flat = this.service.addPolygon('zone_flat', [
            [1.3, 103.8],
            [1.4, 103.9],
            [1.5, 103.7],
        ]);
        assert.deepEqual(
            flat.paths,
            [
                { lat: 1.3, lng: 103.8 },
                { lat: 1.4, lng: 103.9 },
                { lat: 1.5, lng: 103.7 },
            ],
            'a single ring stays flat'
        );

        const multi = this.service.addPolygon('zone_multi', [
            [
                [1.3, 103.8],
                [1.4, 103.9],
                [1.5, 103.7],
            ],
            [
                [1.31, 103.81],
                [1.41, 103.91],
                [1.51, 103.71],
            ],
        ]);
        assert.strictEqual(multi.paths.length, 2, 'nested rings stay nested');

        const mixed = this.service.addPolygon('zone_mixed', [
            [
                [1.3, 103.8],
                [1.4, 103.9],
                [1.5, 103.7],
            ],
            [[1.31, 103.81]],
        ]);
        assert.deepEqual(mixed.paths[0], { lat: 1.3, lng: 103.8 }, 'a ring too short to close is dropped, leaving one');
    });

    test('a polygon remembers which overlay was last touched', function (assert) {
        this.useMap();
        const seen = [];
        const polygon = this.service.addPolygon(
            'zone_1',
            [
                [1.3, 103.8],
                [1.4, 103.9],
                [1.5, 103.7],
            ],
            { onRightClick: (event) => seen.push(event.type) }
        );

        polygon.fire('click');
        assert.strictEqual(this.service._selectedOverlay, polygon, 'a click selects it');

        this.service._selectedOverlay = null;
        polygon.fire('rightclick', { latLng: { lat: () => 1.3, lng: () => 103.8 } });
        assert.strictEqual(this.service._selectedOverlay, polygon, 'so does a right-click');
        assert.deepEqual(seen, ['rightclick'], 'and the handler is told');
    });

    test('removing an overlay detaches it and forgets it', function (assert) {
        this.useMap();
        const polygon = this.service.addPolygon('zone_1', [
            [1.3, 103.8],
            [1.4, 103.9],
            [1.5, 103.7],
        ]);
        let cleaned = 0;
        polygon.__tooltipCleanup = () => (cleaned += 1);

        this.service.removeOverlay('zone_1');
        assert.strictEqual(cleaned, 1);
        assert.deepEqual(polygon.setMapCalls, [null]);
        assert.strictEqual(this.service._overlays.size, 0);

        this.service.removeOverlay('zone_1');
        assert.strictEqual(this.service._overlays.size, 0, 'removing an unknown overlay is harmless');
    });

    test('a routing control draws one polyline per style and registers its handle', async function (assert) {
        assert.strictEqual(await this.service.addRoutingControl({ waypoints: [[1.3, 103.8]] }), null, 'nothing without a map');

        this.useMap();
        this.service._supportsAdvancedMarkers = false;
        assert.strictEqual(await this.service.addRoutingControl(null), null, 'nor without a route');
        assert.strictEqual(await this.service.addRoutingControl({ waypoints: [] }), null, 'nor for a route with no waypoints');

        const route = {
            engine: 'osrm',
            raw: { source: 'osrm' },
            bounds: 'the-bounds',
            waypoints: [
                [1.3, 103.8],
                [1.4, 103.9],
            ],
            coordinates: [
                [1.3, 103.8],
                [1.35, 103.85],
                [1.4, 103.9],
            ],
        };

        const handle = await this.service.addRoutingControl(route, {
            id: 'route_1',
            tag: 'driver_1',
            polylineOptions: {
                styles: [
                    { color: '#111111', weight: 7, opacity: 0.8 },
                    { color: '#222222', weight: 3, opacity: 0.9 },
                ],
            },
        });

        assert.strictEqual(handle.id, 'route_1');
        assert.strictEqual(handle.engine, 'osrm');
        assert.strictEqual(handle.tag, 'driver_1');
        assert.strictEqual(handle.raw, route.raw);
        assert.strictEqual(handle.bounds, 'the-bounds');
        assert.deepEqual(handle.polylineIds, ['route_1:polyline:0', 'route_1:polyline:1']);
        assert.deepEqual(handle.markerIds, ['route_1:marker:0', 'route_1:marker:1']);
        assert.strictEqual(this.service._overlays.get('route_1:polyline:0').strokeColor, '#111111');
        assert.strictEqual(this.service._routingControls.get('route_1'), handle);
    });

    test('an unstyled routing control takes the Fleet-Ops route blue', async function (assert) {
        this.useMap();
        this.service._supportsAdvancedMarkers = false;
        const route = {
            waypoints: [[1.3, 103.8]],
            coordinates: [
                [1.3, 103.8],
                [1.4, 103.9],
            ],
        };

        const defaulted = await this.service.addRoutingControl(route, { id: 'route_default' });
        const line = this.service._overlays.get('route_default:polyline:0');
        assert.strictEqual(line.strokeColor, '#2563eb');
        assert.strictEqual(line.strokeWeight, 4);
        assert.strictEqual(line.strokeOpacity, 0.85);
        assert.strictEqual(defaulted.tag, null);

        await this.service.addRoutingControl(route, { id: 'route_colored', color: '#ff0000' });
        assert.strictEqual(this.service._overlays.get('route_colored:polyline:0').strokeColor, '#ff0000', 'a bare colour is used when there are no polyline options');

        await this.service.addRoutingControl(route, { id: 'route_styled', color: '#ff0000', polylineOptions: { color: '#00ff00', weight: 9, opacity: 0.5 } });
        assert.strictEqual(this.service._overlays.get('route_styled:polyline:0').strokeColor, '#00ff00', 'polyline options win');

        const noLine = await this.service.addRoutingControl({ waypoints: [[1.3, 103.8]] }, { id: 'route_bare' });
        assert.deepEqual(noLine.polylineIds, [], 'a route with no coordinates draws no line');

        const first = await this.service.addRoutingControl(route);
        const second = await this.service.addRoutingControl(route);
        assert.true(first.id.startsWith('route:'));
        assert.notStrictEqual(first.id, second.id, 'generated ids do not collide');
    });

    test('route markers can be suppressed, overridden or skipped', async function (assert) {
        this.useMap();
        this.service._supportsAdvancedMarkers = false;
        const route = {
            waypoints: [
                [1.3, 103.8],
                [1.4, 103.9],
            ],
        };

        const suppressed = await this.service.addRoutingControl(route, { id: 'route_bare', suppressMarkers: true });
        assert.deepEqual(suppressed.markerIds, []);

        const overridden = await this.service.addRoutingControl(route, { id: 'route_override', markerWaypoints: [[1.5, 103.5]] });
        assert.deepEqual(overridden.markerIds, ['route_override:marker:0']);

        const skipped = await this.service.addRoutingControl(route, {
            id: 'route_skip',
            createMarker: (waypoint, index) => (index === 0 ? null : false),
        });
        assert.deepEqual(skipped.markerIds, [], 'both null and false skip the marker');
    });

    test('removing a routing control takes its markers and lines with it', async function (assert) {
        this.useMap();
        this.service._supportsAdvancedMarkers = false;
        const route = {
            waypoints: [[1.3, 103.8]],
            coordinates: [
                [1.3, 103.8],
                [1.4, 103.9],
            ],
        };

        await this.service.addRoutingControl(route, { id: 'route_1' });
        assert.strictEqual(this.service._markers.size, 1);
        assert.strictEqual(this.service._overlays.size, 1);

        assert.true(this.service.removeRoutingControl('route_1'), 'removal by id');
        assert.strictEqual(this.service._markers.size, 0);
        assert.strictEqual(this.service._overlays.size, 0);

        const second = await this.service.addRoutingControl(route, { id: 'route_2' });
        assert.true(this.service.removeRoutingControl(second), 'or by handle');
        assert.false(this.service.removeRoutingControl('route_gone'), 'an unknown id reports nothing removed');
        assert.true(this.service.removeRoutingControl({ id: 'route_empty' }), 'a handle with nothing on it is still accepted');
    });

    test('positionWaypoints flies to a lone point and fits a box for more', function (assert) {
        assert.strictEqual(this.service.positionWaypoints([[1.3, 103.8]]), null, 'nothing without a map');

        const map = this.useMap();
        const pans = [];
        this.service.panBy = (x, y) => pans.push([x, y]);

        assert.true(this.service.positionWaypoints([[1.3, 103.8]], { singlePointZoom: 16, panBy: [10, 4] }));
        assert.deepEqual(map.calls.at(-1), ['panTo', { lat: 1.3, lng: 103.8 }], 'a lone point is flown to');
        this.onceListeners.at(-1)[1]();
        assert.deepEqual(pans, [[10, 4]], 'and panned once the map settles');

        const fits = [];
        this.service.fitBounds = (bounds, options) => fits.push([bounds, options.maxZoom, options.paddingBottomRight]);

        this.service.positionWaypoints([
            [1.3, 103.8],
            [1.4, 103.9],
        ]);
        assert.strictEqual(fits.at(-1)[1], 15, 'two points may zoom further in');
        assert.deepEqual(fits.at(-1)[2], [300, 0], 'the default padding leaves room for the overlay');

        this.service.positionWaypoints([
            [1.3, 103.8],
            [1.4, 103.9],
            [1.5, 104.0],
        ]);
        assert.strictEqual(fits.at(-1)[1], 14, 'three or more stay further out');

        this.service.positionWaypoints('the-bounds', { isBounds: true, maxZoom: 11, paddingBottomRight: [0, 20] });
        assert.deepEqual(fits.at(-1), ['the-bounds', 11, [0, 20]], 'declared bounds are passed straight through');

        this.onceListeners.at(-1)[1]();
        assert.deepEqual(pans.at(-1), [0, 0], 'with no pan asked for, it pans by nothing');

        assert.strictEqual(this.service.positionWaypoints([]), null, 'an empty list positions nothing');
    });

    test('removeLayer detaches a layer however it can and clears the selection', function (assert) {
        this.useMap();
        assert.strictEqual(this.service.removeLayer(null), undefined, 'nothing to remove');

        const overlay = this.service.addPolygon('zone_1', [
            [1.3, 103.8],
            [1.4, 103.9],
            [1.5, 103.7],
        ]);
        let cleaned = 0;
        overlay.__tooltipCleanup = () => (cleaned += 1);
        this.service._selectedOverlay = overlay;
        this.service._draftOverlays.add(overlay);
        this.service._pendingDeletedDrafts.add(overlay);

        this.service.removeLayer(overlay);
        assert.strictEqual(cleaned, 1);
        assert.deepEqual(overlay.setMapCalls, [null]);
        assert.strictEqual(this.service._selectedOverlay, null, 'the selection is cleared');
        assert.strictEqual(this.service._draftOverlays.size, 0);
        assert.strictEqual(this.service._pendingDeletedDrafts.size, 0);

        const advanced = { map: this.service._map };
        this.service.removeLayer(advanced);
        assert.strictEqual(advanced.map, null, 'a layer with no setMap is detached by clearing its map');

        this.service.removeLayer({ nothing: true });
        assert.ok(true, 'and a layer with neither is left alone');
    });

    test('a layer is shown and hidden, and reports which it is', function (assert) {
        const map = this.useMap();
        const overlay = this.service.addPolygon('zone_1', [
            [1.3, 103.8],
            [1.4, 103.9],
            [1.5, 103.7],
        ]);

        assert.true(this.service.isLayerHidden(null), 'a layer that is not there counts as hidden');
        assert.false(this.service.isLayerVisible(null));
        assert.strictEqual(this.service.showLayer(null), undefined);
        assert.strictEqual(this.service.hideLayer(null), undefined);

        this.service.hideLayer(overlay);
        assert.deepEqual(overlay.setMapCalls.at(-1), null);
        assert.true(this.service.isLayerHidden(overlay));

        this.service.showLayer(overlay);
        assert.strictEqual(overlay.setMapCalls.at(-1), map);
        assert.true(this.service.isLayerVisible(overlay));
        assert.deepEqual(overlay.setOptionsCalls.at(-1), { strokeOpacity: 1, fillOpacity: 0.2, visible: true }, 'showing restores the presentation');

        const advanced = { map: null };
        this.service.showLayer(advanced);
        assert.strictEqual(advanced.map, map, 'a layer with no setMap is shown by setting its map');
        this.service.hideLayer(advanced);
        assert.strictEqual(advanced.map, null);
    });

    test('a layer carrying a label moves it along with the layer', function (assert) {
        const map = this.useMap();
        const labelSetMaps = [];
        const overlay = this.service.addPolygon('zone_1', [
            [1.3, 103.8],
            [1.4, 103.9],
            [1.5, 103.7],
        ]);
        overlay.__labelMarker = { setMap: (value) => labelSetMaps.push(value) };
        let hidden = 0;
        overlay.__tooltipCleanup = Object.assign(() => {}, { hide: () => (hidden += 1) });

        this.service.hideLayer(overlay);
        assert.deepEqual(labelSetMaps, [null], 'the label goes with it');
        assert.strictEqual(hidden, 1, 'and the hover tooltip is told to hide');

        this.service.showLayer(overlay);
        assert.strictEqual(labelSetMaps.at(-1), map, 'and comes back with it');

        const plainLabel = { map: 'stale' };
        this.service.hideLayer(Object.assign({ setMap() {} }, { __labelMarker: plainLabel }));
        assert.strictEqual(plainLabel.map, null, 'a label with no setMap is detached by its map property');
    });

    test('drawing modes only start once a drawing manager exists', function (assert) {
        this.useMap();

        this.service.enableDrawingMode('polygon');
        assert.strictEqual(this.service._drawingOnCreate, undefined, 'nothing is armed without a manager');

        const modes = [];
        const maps = [];
        this.service._drawingManager = {
            setDrawingMode: (mode) => modes.push(mode),
            setMap: (map) => maps.push(map),
        };

        for (const type of ['polygon', 'circle', 'rectangle', 'polyline', 'marker']) {
            this.service.enableDrawingMode(type);
        }
        assert.deepEqual(modes, ['polygon', 'circle', 'rectangle', 'polyline', 'marker'], 'every shape maps to its overlay type');
        assert.strictEqual(maps.at(-1), this.service._map, 'the manager is put on the map');

        this.service.enableDrawingMode('hexagon');
        assert.strictEqual(modes.at(-1), null, 'an unsupported shape clears the mode');

        const created = [];
        this.service.enableDrawingMode('polygon', { onCreate: (shape) => created.push(shape) });
        assert.strictEqual(typeof this.service._drawingOnCreate, 'function', 'a create handler is armed');
        this.service.enableDrawingMode('polygon', { onCreate: 'not a function' });
        assert.strictEqual(this.service._drawingOnCreate, null, 'and cleared when none is given');
    });

    test('disabling drawing tells listeners it stopped', function (assert) {
        this.useMap();
        const stops = [];
        this.service.on('draw:drawstop', (payload) => stops.push(payload));

        const modes = [];
        this.service._drawingManager = { setDrawingMode: (mode) => modes.push(mode), setMap() {} };
        this.service._drawingOnCreate = () => {};

        this.service.disableDrawingMode();
        assert.deepEqual(modes, [null], 'the mode is cleared');
        assert.strictEqual(this.service._drawingOnCreate, null);
        assert.deepEqual(stops, [{}], 'and the synthetic stop reaches listeners');

        this.service._drawingManager = null;
        this.service.disableDrawingMode();
        assert.strictEqual(stops.length, 2, 'it still fires without a manager');
    });

    test('a listener that throws does not stop the others hearing the event', function (assert) {
        this.useMap();
        const seen = [];

        this.service.on('draw:drawstop', () => {
            throw new Error('bad listener');
        });
        this.service.on('draw:drawstop', () => seen.push('second'));

        this.service.disableDrawingMode();
        assert.deepEqual(seen, ['second'], 'the second handler still runs');
    });

    test('popups open on the map and close by id', function (assert) {
        assert.strictEqual(this.service.openPopup('popup_1', 1.3, 103.8, '<b>Depot</b>'), null, 'nothing opens without a map');

        const map = this.useMap();
        const popup = this.service.openPopup('popup_1', 1.3, 103.8, '<b>Depot</b>');

        assert.strictEqual(this.service._popups.get('popup_1'), popup);
        assert.strictEqual(popup.content, '<b>Depot</b>');
        assert.deepEqual(popup.position, { lat: 1.3, lng: 103.8 });
        assert.deepEqual(popup.openCalls, [{ map }]);

        const element = document.createElement('div');
        const fromElement = this.service.openPopup('popup_2', 1.3, 103.8, element);
        assert.strictEqual(fromElement.content, element, 'an element is passed through as content');

        this.service.closePopup('popup_1');
        assert.strictEqual(popup.closeCalls, 1);
        assert.strictEqual(this.service._popups.size, 1);

        this.service.closePopup('popup_1');
        assert.strictEqual(popup.closeCalls, 1, 'closing an unknown popup is harmless');
    });

    test('context menus are registered, read back and removed', function (assert) {
        const items = [{ label: 'Centre here' }];

        assert.deepEqual(this.service.getContextMenuItems('map'), [], 'an unregistered target offers nothing');

        this.service.registerContextMenu('map', items);
        assert.strictEqual(this.service.getContextMenuItems('map'), items);

        const el = document.createElement('div');
        document.body.appendChild(el);
        this.service._contextMenuEls.set('map', el);

        this.service.removeContextMenu('map');
        assert.deepEqual(this.service.getContextMenuItems('map'), [], 'the items are gone');
        assert.notOk(el.parentNode, 'and the element it rendered is detached');

        this.service.removeContextMenu('map');
        assert.ok(true, 'removing an unregistered target is harmless');
    });

    test('a context menu renders at the pointer and closes when an item is chosen', function (assert) {
        this.useMap();
        const chosen = [];

        try {
            this.service.showContextMenu(null, [{ label: 'Centre here' }]);
            this.service.showContextMenu({ latlng: { lat: 1.3, lng: 103.8 } }, []);
            assert.notOk(document.querySelector('.fleetops-google-contextmenu'), 'neither a missing point nor an empty menu opens anything');

            this.service.showContextMenu({ latlng: { lat: 'nowhere', lng: 'nowhere' } }, [{ label: 'Centre here', action() {} }]);
            assert.notOk(document.querySelector('.fleetops-google-contextmenu'), 'nor a point that is not a point');

            this.service.showContextMenu({ latlng: { lat: 1.3, lng: 103.8 }, originalEvent: { clientX: 120, clientY: 80 } }, [
                { label: 'Centre here', action: (payload) => chosen.push(payload.latlng) },
                { separator: true },
                { label: 'Add place', action() {} },
            ]);

            const menu = document.querySelector('.fleetops-google-contextmenu');
            assert.ok(menu, 'the menu is rendered');
            assert.strictEqual(menu.style.left, '120px', 'at the pointer');
            assert.strictEqual(menu.style.top, '80px');
            assert.strictEqual(menu.querySelectorAll('.fleetops-google-contextmenu-item').length, 2);
            assert.strictEqual(menu.querySelectorAll('.fleetops-google-contextmenu-separator').length, 1);

            menu.querySelector('.fleetops-google-contextmenu-item').dispatchEvent(new MouseEvent('click', { cancelable: true }));
            assert.deepEqual(chosen, [{ lat: 1.3, lng: 103.8 }], 'the action is handed the point that was clicked');
            assert.notOk(document.querySelector('.fleetops-google-contextmenu'), 'and choosing an item closes the menu');

            this.service.showContextMenu({ latlng: { lat: () => 1.3, lng: () => 103.8 } }, [{ label: 'Centre here', action() {} }]);
            const fromFunctions = document.querySelector('.fleetops-google-contextmenu');
            assert.ok(fromFunctions, 'a point exposed as functions is read the same way');
            assert.strictEqual(fromFunctions.style.left, '0px', 'and with no pointer it sits at the origin');

            this.service.showContextMenu({ latlng: { lat: 1.4, lng: 103.9 } }, [{ label: 'Second', action() {} }]);
            assert.strictEqual(document.querySelectorAll('.fleetops-google-contextmenu').length, 1, 'a second menu replaces the first');
        } finally {
            this.service.closeContextMenu();
            document.querySelector('.fleetops-google-contextmenu')?.remove();
        }
    });

    test('closeContextMenu runs the cleanup once and forgets it', function (assert) {
        let cleaned = 0;
        this.service._contextMenuCleanup = () => (cleaned += 1);

        this.service.closeContextMenu();
        this.service.closeContextMenu();

        assert.strictEqual(cleaned, 1, 'the cleanup runs once and is dropped');
        assert.strictEqual(this.service._contextMenuCleanup, null);
    });

    test('map events are subscribed under their Google names and unsubscribed', function (assert) {
        const handler = () => {};
        this.service.on('click', handler);
        assert.strictEqual(this.service._eventListeners.size, 0, 'nothing is wired before there is a map');

        const map = this.useMap();
        const seen = [];

        this.service.on('click', (payload) => seen.push(payload));
        assert.deepEqual(map.calls.at(-1), ['addListener', 'click']);

        this.service.on('moveend', () => {});
        assert.deepEqual(map.calls.at(-1), ['addListener', 'idle'], 'moveend listens for Google s idle');
        this.service.on('zoomend', () => {});
        assert.deepEqual(map.calls.at(-1), ['addListener', 'zoom_changed']);
        this.service.on('load', () => {});
        assert.deepEqual(map.calls.at(-1), ['addListener', 'tilesloaded']);
        this.service.on('contextmenu', () => {});
        assert.deepEqual(map.calls.at(-1), ['addListener', 'rightclick']);

        map.listeners[0].handler({ latLng: { lat: () => 1.3, lng: () => 103.8 }, domEvent: 'the-dom-event' });
        assert.deepEqual(seen[0].latlng, { lat: 1.3, lng: 103.8 }, 'the payload is normalised');
        assert.strictEqual(seen[0].type, 'click');
        assert.strictEqual(seen[0].originalEvent, 'the-dom-event');

        map.listeners[0].handler(null);
        assert.deepEqual(seen[1], { type: 'click' }, 'an empty event carries only its name');

        map.listeners[0].handler({ noLatLng: true });
        assert.strictEqual(seen[2].latlng, null, 'an event with no point reports none');
        assert.deepEqual(seen[2].originalEvent, { noLatLng: true }, 'and falls back to the raw event');
    });

    test('a draw listener is held on the adapter rather than the map', function (assert) {
        const map = this.useMap();
        const seen = [];
        const handler = (payload) => seen.push(payload);

        this.service.on('draw:created', handler);
        assert.deepEqual(
            map.calls.filter(([kind]) => kind === 'addListener'),
            [],
            'nothing is wired onto the Google map'
        );
        assert.strictEqual(this.service._eventListeners.get('draw:created').length, 1);

        this.service.on('draw:created', () => seen.push('second'));
        assert.strictEqual(this.service._eventListeners.get('draw:created').length, 2, 'a second listener joins the first');

        this.service.off('draw:created', handler);
        assert.strictEqual(this.service._eventListeners.get('draw:created').length, 1, 'and can be removed on its own');
    });

    test('unsubscribing removes the Google listener too', function (assert) {
        const map = this.useMap();
        const handler = () => {};

        this.service.on('click', handler);
        const listener = map.listeners[0];

        this.service.off('click', handler);
        assert.deepEqual(this.removedListeners, [listener], 'the Google listener is released');
        assert.strictEqual(this.service._eventListeners.get('click').length, 0);

        this.service.off('click', handler);
        this.service.off('zoomend', handler);
        assert.strictEqual(this.removedListeners.length, 1, 'unsubscribing something that is not there does nothing');
    });

    test('a one-shot listener fires once, for map and draw events alike', function (assert) {
        const handler = () => {};
        assert.strictEqual(this.service.once('click', handler), undefined, 'nothing is wired before there is a map');

        this.useMap();
        const seen = [];

        const listener = this.service.once('click', (payload) => seen.push(payload.type));
        assert.strictEqual(this.onceListeners.at(-1)[0], 'click', 'a map event goes through Google s own once');
        assert.strictEqual(listener, this.onceListeners.at(-1));
        this.onceListeners.at(-1)[1]({ latLng: { lat: () => 1.3, lng: () => 103.8 } });
        assert.deepEqual(seen, ['click']);

        const drawSeen = [];
        this.service.once('draw:created', (payload) => drawSeen.push(payload));
        assert.strictEqual(this.service._eventListeners.get('draw:created').length, 1);
        this.service._eventListeners.get('draw:created')[0].handler('first');
        assert.deepEqual(drawSeen, ['first']);
        assert.strictEqual(this.service._eventListeners.get('draw:created').length, 0, 'a draw one-shot removes itself');
    });

    test('distanceBetween measures through the geometry library', function (assert) {
        const metres = this.service.distanceBetween(1.3521, 103.8198, 1.3621, 103.8198);

        assert.true(metres > 1050 && metres < 1150, 'a hundredth of a degree of latitude is about 1.1km');
    });

    test('geojson layers are added, styled and removed by id', function (assert) {
        const geojson = { type: 'Point', coordinates: [103.8198, 1.3521] };
        assert.strictEqual(this.service.addGeoJson('geo_1', geojson), null, 'nothing is added without a map');

        const map = this.useMap();
        const layer = this.service.addGeoJson('geo_1', geojson, { style: { strokeColor: '#ff0000' } });
        assert.strictEqual(this.service._geojsonLayers.get('geo_1'), layer);
        assert.strictEqual(layer.map, map);
        assert.deepEqual(layer.geoJson, [geojson]);
        assert.deepEqual(layer.styles, [{ strokeColor: '#ff0000' }]);

        const unstyled = this.service.addGeoJson('geo_2', geojson);
        assert.deepEqual(unstyled.styles, [], 'a layer with no style is left alone');

        this.service.removeGeoJson('geo_1');
        assert.deepEqual(layer.setMapCalls, [null]);
        assert.strictEqual(this.service._geojsonLayers.size, 1);

        this.service.removeGeoJson('geo_1');
        assert.strictEqual(this.service._geojsonLayers.size, 1, 'removing an unknown layer is harmless');
    });

    test('a custom tile layer is built from the url template and replaces the last one', function (assert) {
        assert.strictEqual(this.service.setTileLayer('https://tiles.example.test/{z}/{x}/{y}.png'), undefined, 'nothing to tile without a map');

        const map = this.useMap();
        this.service.setTileLayer('https://tiles.example.test/{z}/{x}/{y}.png');

        const first = this.imageMapTypes.at(-1);
        assert.strictEqual(first.getTileUrl({ x: 2, y: 3 }, 10), 'https://tiles.example.test/10/2/3.png', 'the template is filled in');
        assert.strictEqual(map.overlayMapTypes.cleared, 0, 'the first layer replaces nothing');
        assert.deepEqual(map.overlayMapTypes.inserted.at(-1), [0, first], 'and goes on top');
        assert.strictEqual(first.name, 'Custom Tiles', 'the default layer name');
        assert.strictEqual(first.opacity, 1);

        this.service.setTileLayer('https://other.example.test/{z}/{x}/{y}.png');
        assert.strictEqual(map.overlayMapTypes.cleared, 1, 'a second layer clears the first');
        assert.notStrictEqual(this.service._customTileLayer, first);
    });

    test('the draw toolbar is built once, into the map element, and remembers its config', function (assert) {
        this.service.showDrawControl({ tools: ['polygon'] });
        assert.notOk(this.mapDiv.querySelector('.fleetops-google-draw'), 'nothing is built without a map');
        assert.deepEqual(this.service._drawControlConfig, { tools: ['polygon'] }, 'but the config is remembered');

        this.useMap();
        this.service.showDrawControl({ tools: ['polygon'] });

        const toolbar = this.mapDiv.querySelector('.fleetops-google-draw');
        assert.ok(toolbar, 'the toolbar goes into the map element');
        assert.strictEqual(toolbar.style.display, '', 'and is shown');
        assert.strictEqual(this.service._drawControlEl, toolbar);

        this.service.showDrawControl({ tools: ['polygon'] });
        assert.strictEqual(this.mapDiv.querySelectorAll('.fleetops-google-draw').length, 1, 'showing it again does not build a second');
    });

    test('the toolbar renders the tools it is asked for and starts their drawing mode', function (assert) {
        this.useMap();
        const modes = [];
        this.service._drawingManager = { setDrawingMode: (mode) => modes.push(mode), setMap() {} };

        this.service.showDrawControl();
        const toolbar = this.service._drawControlEl;
        assert.deepEqual(
            [...toolbar.__toolGroup.children].map((el) => el.getAttribute('aria-label')),
            ['polygon', 'rectangle', 'circle'],
            'all three shapes by default'
        );
        assert.strictEqual(toolbar.__actionSection.style.display, 'none', 'with no edit or delete, the action bar is hidden');

        toolbar.__toolGroup.querySelector('.fleetops-google-draw-draw-polygon').dispatchEvent(new MouseEvent('click', { cancelable: true }));
        assert.deepEqual(modes, ['polygon'], 'a tool button starts its drawing mode');

        this.service.showDrawControl({ tools: ['circle'] });
        assert.deepEqual(
            [...toolbar.__toolGroup.children].map((el) => el.getAttribute('aria-label')),
            ['circle'],
            'a narrower config re-renders the group'
        );

        assert.strictEqual(toolbar.__toolGroup.querySelector('.sr-only').textContent, 'circle', 'each button is labelled for screen readers');
    });

    test('the toolbar shows edit and delete only when they are allowed', function (assert) {
        this.useMap();
        this.service.showDrawControl({ allowEdit: true, allowDelete: true });

        const toolbar = this.service._drawControlEl;
        assert.deepEqual(
            [...toolbar.__actionGroup.children].map((el) => el.getAttribute('aria-label')),
            ['edit', 'delete']
        );
        assert.strictEqual(toolbar.__actionSection.style.display, '', 'the action bar is shown');

        this.service.showDrawControl({ allowEdit: true });
        assert.deepEqual(
            [...toolbar.__actionGroup.children].map((el) => el.getAttribute('aria-label')),
            ['edit'],
            'delete alone can be withheld'
        );

        this.service.showDrawControl({ allowDelete: true });
        assert.deepEqual(
            [...toolbar.__actionGroup.children].map((el) => el.getAttribute('aria-label')),
            ['delete']
        );
    });

    test('a config carrying a default mode starts drawing straight away', function (assert) {
        this.useMap();
        const modes = [];
        this.service._drawingManager = { setDrawingMode: (mode) => modes.push(mode), setMap() {} };

        this.service.showDrawControl({ defaultMode: 'rectangle' });

        assert.deepEqual(modes, ['rectangle']);
    });

    test('hiding the draw control puts back anything a delete was holding', function (assert) {
        const map = this.useMap();
        this.service.showDrawControl({ allowDelete: true });

        // A draft the user deleted but has not saved is held aside until the toolbar closes.
        const held = {
            setMapCalls: [],
            setMap(value) {
                this.setMapCalls.push(value);
            },
        };
        this.service._pendingDeletedDrafts.add(held);

        const modes = [];
        this.service._drawingManager = { setDrawingMode: (mode) => modes.push(mode), setMap() {} };
        this.service._drawActionEl = document.createElement('div');
        this.mapDiv.appendChild(this.service._drawActionEl);
        const actionEl = this.service._drawActionEl;

        this.service.hideDrawControl();

        assert.strictEqual(this.service._drawControlEl.style.display, 'none', 'the toolbar is hidden');
        assert.notOk(actionEl.parentNode, 'the action bar is detached');
        assert.strictEqual(this.service._drawActionEl, null);
        assert.deepEqual(held.setMapCalls, [map], 'the held draft is put back on the map');
        assert.strictEqual(this.service._pendingDeletedDrafts.size, 0);
        assert.deepEqual(modes, [null], 'and drawing is switched off');
    });

    test('hiding a draw control that was never shown is harmless', function (assert) {
        this.useMap();

        this.service.hideDrawControl();

        assert.strictEqual(this.service._drawControlEl, null, 'there was nothing to hide');
        assert.ok(true, 'and nothing threw');
    });

    test('the toolbar sits below the map topbar when there is one', function (assert) {
        this.useMap();

        this.service.showDrawControl();
        assert.strictEqual(this.service._drawControlEl.style.top, '16px', 'with no topbar it takes the minimum offset');

        const topbar = document.createElement('div');
        topbar.id = 'map-topbar-container';
        topbar.style.height = '120px';
        document.getElementById('ember-testing').appendChild(topbar);

        try {
            // The testing container is scaled, so the offset is derived from what the browser
            // actually measures rather than from the height that was set.
            const measured = topbar.getBoundingClientRect().height;
            assert.true(measured > 0, 'the topbar has a height to clear');

            this.service.showDrawControl();
            assert.strictEqual(this.service._drawControlEl.style.top, `${Math.round(measured + 12)}px`, 'and clears the topbar when one is on the page');
            assert.true(Math.round(measured + 12) > 16, 'which is past the minimum offset');
        } finally {
            topbar.remove();
        }
    });

    test('a polygon given a tooltip is labelled at the centre of its ring', function (assert) {
        const map = this.useMap();

        const polygon = this.service.addPolygon(
            'zone_1',
            [
                [0, 0],
                [0, 30],
                [30, 30],
                [30, 0],
            ],
            { tooltip: 'Zone A' }
        );

        assert.strictEqual(polygon.__labelText, 'Zone A');
        const label = polygon.__labelMarker;
        assert.ok(label, 'a label overlay is built');
        assert.deepEqual(label.position, { lat: 15, lng: 15 }, 'at the centroid of the ring');
        assert.strictEqual(label.title, 'Zone A');
        assert.strictEqual(label.content.className, 'fleetops-google-polygon-label');
        assert.strictEqual(label.content.textContent, 'Zone A');
        assert.deepEqual(label.setMapCalls, [], 'built detached: google-live-map hides the polygon straight after creating it');
        assert.strictEqual(typeof polygon.__tooltipCleanup, 'function', 'with hover handlers to clean up');

        this.service.showLayer(polygon);
        assert.strictEqual(label.setMapCalls.at(-1), map, 'and only attached once the layer is shown');
    });

    test('the label overlay draws itself into the map pane and cleans up after itself', function (assert) {
        this.useMap();
        this.service.addPolygon(
            'zone_1',
            [
                [0, 0],
                [0, 30],
                [30, 30],
            ],
            { tooltip: 'Zone A' }
        );

        const label = this.overlayViews.at(-1);
        // The overlay only renders once it is on the map, which is what showing the layer does.
        this.service.showLayer(this.service._overlays.get('zone_1'));
        const container = this.panes.overlayMouseTarget.firstElementChild;
        assert.ok(container, 'the overlay adds a container to the map pane');
        assert.strictEqual(container.firstElementChild, label.content, 'holding the label content');
        assert.strictEqual(container.style.left, '40px', 'positioned from the projection');
        assert.strictEqual(container.style.top, '60px');
        assert.strictEqual(container.style.transform, 'translate(-50%, -50%)', 'and centred on the point');

        label.setMap(null);
        assert.notOk(this.panes.overlayMouseTarget.firstElementChild, 'removing it takes the container away');

        label.draw();
        assert.ok(true, 'and drawing after removal is harmless');
    });

    test('the label follows the cursor while the polygon is hovered', function (assert) {
        this.useMap();
        this.mapDiv.style.width = '400px';
        this.mapDiv.style.height = '300px';

        const polygon = this.service.addPolygon(
            'zone_1',
            [
                [0, 0],
                [0, 30],
                [30, 30],
            ],
            { tooltip: 'Zone A' }
        );
        const label = polygon.__labelMarker;
        this.service.showLayer(polygon);
        const container = this.panes.overlayMouseTarget.firstElementChild;
        const rect = this.mapDiv.getBoundingClientRect();

        polygon.fire('mouseover', { domEvent: { clientX: rect.left + 100, clientY: rect.top + 50 } });
        assert.true(label.__followCursor, 'the label leaves its anchor');
        assert.strictEqual(container.style.transform, 'translate(0px, 0px)', 'and is placed at the pointer');
        const afterOver = container.style.left;

        polygon.fire('mousemove', { domEvent: { clientX: rect.left + 200, clientY: rect.top + 50 } });
        assert.notStrictEqual(container.style.left, afterOver, 'and tracks it as it moves');

        polygon.fire('mouseout', {});
        assert.false(label.__followCursor, 'leaving the polygon returns it to the anchor');
        assert.strictEqual(container.style.transform, 'translate(-50%, -50%)');

        polygon.fire('mouseover', {});
        assert.false(label.__followCursor, 'an event with no pointer is ignored');
    });

    test('the hover handlers are released when the overlay goes', function (assert) {
        this.useMap();
        const polygon = this.service.addPolygon(
            'zone_1',
            [
                [0, 0],
                [0, 30],
                [30, 30],
            ],
            { tooltip: 'Zone A' }
        );

        this.service.removeOverlay('zone_1');

        assert.strictEqual(this.removedListeners.length, 3, 'mouseover, mousemove and mouseout are all released');
        assert.strictEqual(polygon.__labelMarker.setMapCalls.at(-1), null, 'and the label leaves the map');
    });

    test('a polygon with no usable ring gets no label', function (assert) {
        this.useMap();

        const noPoints = this.service.addPolygon('zone_1', [
            [0, 0],
            [0, 30],
            [30, 30],
        ]);
        assert.strictEqual(noPoints.__labelMarker, undefined, 'no tooltip, no label');

        // `#createOverlayLabel` needs a centroid; an empty ring cannot give one.
        this.service._map = null;
        const withoutMap = this.service.addPolygon(
            'zone_2',
            [
                [0, 0],
                [0, 30],
                [30, 30],
            ],
            { tooltip: 'Zone B' }
        );
        assert.strictEqual(withoutMap, null, 'and nothing is drawn without a map at all');
    });

    test('showing a labelled layer rebuilds the label and re-centres it on the current shape', function (assert) {
        const map = this.useMap();
        const polygon = this.service.addPolygon(
            'zone_1',
            [
                [0, 0],
                [0, 30],
                [30, 30],
            ],
            { tooltip: 'Zone A' }
        );

        // A layer that Ember re-created keeps its text but has lost the overlay.
        polygon.__labelMarker = null;
        polygon.getPath = () => ({
            getArray: () => [
                { lat: () => 0, lng: () => 0 },
                { lat: () => 0, lng: () => 60 },
                { lat: () => 60, lng: () => 60 },
            ],
        });

        this.service.showLayer(polygon);

        assert.ok(polygon.__labelMarker, 'the label is rebuilt');
        assert.deepEqual(polygon.__labelMarker.position, { lat: 20, lng: 40 }, 'at the centroid of the shape as it is now');
        assert.strictEqual(polygon.__labelMarker.setMapCalls.at(-1), map);
        assert.strictEqual(polygon.__labelMarker.content.textContent, 'Zone A');
    });

    test('a layer whose path cannot be read falls back to the ring it was built with', function (assert) {
        this.useMap();
        const polygon = this.service.addPolygon(
            'zone_1',
            [
                [0, 0],
                [0, 30],
                [30, 30],
            ],
            { tooltip: 'Zone A' }
        );
        const originalPaths = polygon.__labelPaths;

        polygon.getPath = () => ({ getArray: () => [{ lat: () => NaN, lng: () => NaN }] });
        this.service.showLayer(polygon);

        assert.deepEqual(polygon.__labelPaths, originalPaths, 'the remembered ring is kept');
        assert.deepEqual(polygon.__labelMarker.position, { lat: 10, lng: 20 }, 'and the label stays where it was');
    });

    test('a marker tooltip is shown on hover and torn down with the marker', async function (assert) {
        this.useMap();
        this.service._supportsAdvancedMarkers = true;

        const marker = await this.service.addMarker('marker_1', 1.3, 103.8, { iconUrl: '/assets/pin.png', tooltip: 'Depot' });
        const content = marker.content;

        content.dispatchEvent(new MouseEvent('mouseenter', { clientX: 10, clientY: 10 }));
        const tooltip = document.querySelector('.fleetops-google-hover-tooltip');
        assert.ok(tooltip, 'hovering the marker shows a tooltip');
        assert.strictEqual(tooltip.textContent, 'Depot');

        content.dispatchEvent(new MouseEvent('mouseleave'));
        assert.notOk(
            document.querySelector('.fleetops-google-hover-tooltip')?.isConnected && document.querySelector('.fleetops-google-hover-tooltip').style.opacity === '1',
            'and leaving hides it'
        );

        this.service.removeMarker('marker_1');
        content.dispatchEvent(new MouseEvent('mouseenter', { clientX: 10, clientY: 10 }));
        assert.notOk(document.querySelector('.fleetops-google-hover-tooltip'), 'once removed, the marker no longer shows one');
    });

    test('a classic marker tooltip is attached to the marker itself', async function (assert) {
        this.useMap();
        this.service._supportsAdvancedMarkers = false;

        const marker = await this.service.addMarker('marker_1', 1.3, 103.8, { tooltip: 'Depot' });

        assert.strictEqual(typeof marker.__tooltipCleanup, 'function', 'there is something to clean up');
        assert.deepEqual(
            marker.listeners.map(([event]) => event),
            ['mouseover', 'mousemove', 'mouseout'],
            'and it listens on the marker rather than a DOM node'
        );

        marker.fire('mouseover', { domEvent: { clientX: 10, clientY: 10 } });
        assert.ok(document.querySelector('.fleetops-google-hover-tooltip'), 'hovering shows it');

        this.service.removeMarker('marker_1');
        assert.notOk(document.querySelector('.fleetops-google-hover-tooltip'), 'and removing the marker takes it away');
    });

    /** Puts a draft polygon on the map and opens the toolbar that can edit or delete it. */
    function selectDraftPolygon(test, { latlngs = null } = {}) {
        const overlay = test.service.addPolygon('zone_1', [
            [0, 0],
            [0, 30],
            [30, 30],
        ]);
        overlay.__overlayType = 'polygon';
        overlay.getPath = () => ({
            getArray: () =>
                latlngs ?? [
                    { lat: () => 0, lng: () => 0 },
                    { lat: () => 0, lng: () => 30 },
                    { lat: () => 30, lng: () => 30 },
                ],
        });
        test.service._draftOverlays.add(overlay);
        test.service._selectedOverlay = overlay;
        test.service.showDrawControl({ allowEdit: true, allowDelete: true });
        return overlay;
    }

    const clickToolbar = (service, which) =>
        service._drawControlEl.__actionGroup.querySelector(`.fleetops-google-draw-edit-${which}`).dispatchEvent(new MouseEvent('click', { cancelable: true }));

    const clickAction = (service, label) =>
        [...service._drawActionEl.querySelectorAll('.fleetops-google-draw-action-link')].find((el) => el.textContent === label).dispatchEvent(new MouseEvent('click', { cancelable: true }));

    test('editing is offered only for a selected draft that can be edited', function (assert) {
        this.useMap();
        this.service.showDrawControl({ allowEdit: true });

        clickToolbar(this.service, 'edit');
        assert.strictEqual(this.service._drawActionEl, null, 'nothing is selected');

        const overlay = this.service.addPolygon('zone_1', [
            [0, 0],
            [0, 30],
            [30, 30],
        ]);
        this.service._selectedOverlay = overlay;
        clickToolbar(this.service, 'edit');
        assert.strictEqual(this.service._drawActionEl, null, 'the selection is not a draft');

        this.service._draftOverlays.add(overlay);
        overlay.setEditable = undefined;
        clickToolbar(this.service, 'edit');
        assert.strictEqual(this.service._drawActionEl, null, 'the draft cannot be edited');

        overlay.setEditable = () => {};
        overlay.getPath = undefined;
        overlay.getBounds = undefined;
        overlay.getCenter = undefined;
        clickToolbar(this.service, 'edit');
        assert.strictEqual(this.service._drawActionEl, null, 'and its shape cannot be captured');
    });

    test('editing a draft opens the action bar and saves the edited shape', function (assert) {
        this.useMap();
        const overlay = selectDraftPolygon(this);
        const edited = [];
        this.service.on('draw:edited', (payload) => edited.push(payload));

        clickToolbar(this.service, 'edit');
        assert.true(overlay.editable, 'the shape becomes editable');
        assert.ok(this.service._drawActionEl, 'and an action bar appears');
        assert.deepEqual(
            [...this.service._drawActionEl.querySelectorAll('.fleetops-google-draw-action-link')].map((el) => el.textContent),
            ['Save', 'Cancel'],
            'offering save and cancel'
        );

        clickAction(this.service, 'Save');
        assert.false(overlay.editable, 'saving ends the edit');
        assert.strictEqual(this.service._drawActionEl, null, 'and takes the bar away');
        assert.strictEqual(edited.length, 1);
        assert.strictEqual(edited[0].type, 'draw:edited');
        assert.strictEqual(edited[0].layer, overlay);
        assert.strictEqual(edited[0].layerType, 'polygon');
        assert.strictEqual(edited[0].toGeoJSON(), edited[0].geoJson, 'the payload carries its own geometry');
    });

    test('cancelling an edit puts the shape back as it was', function (assert) {
        this.useMap();
        const overlay = selectDraftPolygon(this);

        clickToolbar(this.service, 'edit');
        clickAction(this.service, 'Cancel');

        assert.deepEqual(
            overlay.restoredPath,
            [
                { lat: 0, lng: 0 },
                { lat: 0, lng: 30 },
                { lat: 30, lng: 30 },
            ],
            'the captured ring is written back'
        );
        assert.false(overlay.editable);
        assert.strictEqual(this.service._drawActionEl, null);
    });

    test('a rectangle and a circle are captured and restored by their own geometry', function (assert) {
        this.useMap();
        this.service.showDrawControl({ allowEdit: true });

        const rectangle = this.service.addCircle('rect_1', 0, 0, 10);
        rectangle.setEditable = (value) => (rectangle.editable = value);
        rectangle.getCenter = undefined;
        rectangle.getRadius = undefined;
        rectangle.getBounds = () => ({
            getNorthEast: () => ({ lat: () => 30, lng: () => 40 }),
            getSouthWest: () => ({ lat: () => 10, lng: () => 20 }),
        });
        this.service._draftOverlays.add(rectangle);
        this.service._selectedOverlay = rectangle;

        clickToolbar(this.service, 'edit');
        clickAction(this.service, 'Cancel');
        assert.deepEqual(rectangle.restoredBounds, { north: 30, east: 40, south: 10, west: 20 }, 'a rectangle restores its bounds');

        const circle = this.service.addCircle('circle_1', 1.3, 103.8, 250);
        circle.setEditable = (value) => (circle.editable = value);
        circle.getCenter = () => ({ lat: () => 1.3, lng: () => 103.8 });
        circle.getRadius = () => 250;
        this.service._draftOverlays.add(circle);
        this.service._selectedOverlay = circle;

        clickToolbar(this.service, 'edit');
        clickAction(this.service, 'Cancel');
        assert.deepEqual(circle.restoredCenter, { lat: 1.3, lng: 103.8 }, 'a circle restores its centre');
        assert.strictEqual(circle.restoredRadius, 250, 'and its radius');
    });

    test('deleting is offered only for a selected draft', function (assert) {
        this.useMap();
        this.service.showDrawControl({ allowDelete: true });

        clickToolbar(this.service, 'remove');
        assert.strictEqual(this.service._drawActionEl, null, 'nothing is selected');

        const overlay = this.service.addPolygon('zone_1', [
            [0, 0],
            [0, 30],
            [30, 30],
        ]);
        this.service._selectedOverlay = overlay;
        clickToolbar(this.service, 'remove');
        assert.strictEqual(this.service._drawActionEl, null, 'and the selection is not a draft');
    });

    test('deleting a draft holds it aside until the delete is saved', function (assert) {
        this.useMap();
        const overlay = selectDraftPolygon(this);
        const deleted = [];
        this.service.on('draw:deleted', (payload) => deleted.push(payload));

        clickToolbar(this.service, 'remove');
        assert.true(this.service._pendingDeletedDrafts.has(overlay), 'the draft is held aside');
        assert.true(overlay.__hidden, 'and hidden from the map');
        assert.deepEqual(
            [...this.service._drawActionEl.querySelectorAll('.fleetops-google-draw-action-link')].map((el) => el.textContent),
            ['Save', 'Cancel', 'Clear All'],
            'a delete also offers clearing everything'
        );

        clickAction(this.service, 'Save');
        assert.strictEqual(deleted.length, 1);
        assert.strictEqual(deleted[0].type, 'draw:deleted');
        assert.strictEqual(deleted[0].layer, overlay);
        assert.strictEqual(deleted[0].layerType, 'polygon');
        assert.strictEqual(this.service._draftOverlays.size, 0, 'the draft is gone for good');
        assert.strictEqual(this.service._pendingDeletedDrafts.size, 0);
        assert.strictEqual(this.service._selectedOverlay, null, 'and nothing is left selected');
        assert.strictEqual(this.service._drawActionEl, null);
    });

    test('cancelling a delete brings the draft back', function (assert) {
        this.useMap();
        const overlay = selectDraftPolygon(this);

        clickToolbar(this.service, 'remove');
        assert.true(overlay.__hidden);

        clickAction(this.service, 'Cancel');
        assert.false(overlay.__hidden, 'the draft is put back on the map');
        assert.strictEqual(this.service._pendingDeletedDrafts.size, 0);
        assert.true(this.service._draftOverlays.has(overlay), 'and is still a draft');
    });

    test('clear all sweeps every draft into the pending delete', function (assert) {
        this.useMap();
        const first = selectDraftPolygon(this);
        const second = this.service.addPolygon('zone_2', [
            [1, 1],
            [1, 31],
            [31, 31],
        ]);
        this.service._draftOverlays.add(second);

        clickToolbar(this.service, 'remove');
        clickAction(this.service, 'Clear All');

        assert.strictEqual(this.service._pendingDeletedDrafts.size, 2, 'both drafts are held');
        assert.true(first.__hidden);
        assert.true(second.__hidden);
    });

    /** Boots a real map so the DrawingManager is created and its overlaycomplete bridge is wired. */
    async function bootDrawingManager(test) {
        await test.service.initializeMap(document.createElement('div'));
        return test.drawingManagers.at(-1);
    }

    const latLng = (lat, lng) => ({ lat: () => lat, lng: () => lng });

    test('a finished polygon becomes a selected draft and is announced', async function (assert) {
        const manager = await bootDrawingManager(this);
        const created = [];
        const announced = [];
        this.service.on('draw:created', (payload) => created.push(payload));
        this.service._drawingOnCreate = (payload) => announced.push(payload);

        const overlay = {
            getPath: () => ({ getArray: () => [latLng(0, 0), latLng(0, 30), latLng(30, 30)] }),
            addListener() {},
        };
        manager.fire('overlaycomplete', { type: 'polygon', overlay });

        assert.strictEqual(this.service._selectedOverlay, overlay, 'the new shape is selected');
        assert.true(this.service._draftOverlays.has(overlay), 'and held as a draft');
        assert.strictEqual(overlay.__overlayType, 'polygon');
        assert.strictEqual(created.length, 1);
        assert.strictEqual(created[0].layer, overlay);
        assert.strictEqual(created[0].layerType, 'polygon');
        assert.strictEqual(created[0].geoJson.type, 'Polygon');
        assert.deepEqual(created[0].geoJson.coordinates[0].at(-1), [0, 0], 'the ring is closed');
        assert.strictEqual(created[0].toGeoJSON(), created[0].geoJson);
        assert.deepEqual(announced, created, 'the create handler hears the same payload');
        assert.deepEqual(manager.modes.at(-1), null, 'and drawing stops');
    });

    test('a finished shape selects the overlay again when it is clicked', async function (assert) {
        const manager = await bootDrawingManager(this);
        const listeners = [];
        const overlay = {
            getPath: () => ({ getArray: () => [latLng(0, 0), latLng(0, 30), latLng(30, 30)] }),
            addListener: (event, handler) => listeners.push([event, handler]),
        };

        manager.fire('overlaycomplete', { type: 'polygon', overlay });
        assert.deepEqual(
            listeners.map(([event]) => event),
            ['click', 'rightclick'],
            'the draft listens for its own selection'
        );

        this.service._selectedOverlay = null;
        listeners[0][1]();
        assert.strictEqual(this.service._selectedOverlay, overlay, 'a click selects it');

        this.service._selectedOverlay = null;
        listeners[1][1]();
        assert.strictEqual(this.service._selectedOverlay, overlay, 'so does a right-click');

        this.service._selectedOverlay = null;
        manager.fire('overlaycomplete', { type: 'polygon', overlay: { getPath: overlay.getPath } });
        assert.ok(this.service._selectedOverlay, 'a shape that cannot listen is still selected');
    });

    test('each finished shape is turned into its own geometry', async function (assert) {
        const manager = await bootDrawingManager(this);
        const created = [];
        this.service.on('draw:created', (payload) => created.push(payload));

        manager.fire('overlaycomplete', {
            type: 'rectangle',
            overlay: {
                getBounds: () => ({ getNorthEast: () => latLng(30, 40), getSouthWest: () => latLng(10, 20) }),
            },
        });
        assert.strictEqual(created.at(-1).layerType, 'rectangle');
        assert.strictEqual(created.at(-1).geoJson.type, 'Polygon');
        assert.strictEqual(created.at(-1).geoJson.coordinates[0].length, 5, 'a rectangle becomes a closed ring of five points');

        manager.fire('overlaycomplete', {
            type: 'circle',
            overlay: { getCenter: () => latLng(1.3, 103.8), getRadius: () => 250 },
        });
        assert.strictEqual(created.at(-1).layerType, 'circle');
        assert.strictEqual(created.at(-1).geoJson.type, 'Feature', 'the geojson Circle helper approximates the circle as a feature');
        assert.strictEqual(created.at(-1).geoJson.geometry.type, 'Polygon');

        manager.fire('overlaycomplete', {
            type: 'polyline',
            overlay: { getPath: () => ({ getArray: () => [latLng(0, 0), latLng(0, 30)] }) },
        });
        assert.strictEqual(created.at(-1).layerType, 'polyline');
        assert.strictEqual(created.at(-1).geoJson.geometry.type, 'LineString', 'a polyline stays a feature');

        manager.fire('overlaycomplete', { type: 'marker', overlay: {} });
        assert.strictEqual(created.at(-1).layerType, 'marker');
        assert.strictEqual(created.at(-1).geoJson, null, 'a marker has no shape to report');

        manager.fire('overlaycomplete', { type: 'hexagon', overlay: {} });
        assert.strictEqual(created.at(-1).layerType, 'hexagon', 'an unmapped type is passed through as it came');
    });

    test('the adapter carries on when the drawing library will not load', async function (assert) {
        const originalImport = window.google.maps.importLibrary;
        window.google.maps.importLibrary = (name) => (name === 'drawing' ? Promise.reject(new Error('offline')) : originalImport(name));

        try {
            await this.service.initializeMap(document.createElement('div'));
            assert.strictEqual(this.service._drawingManager, null, 'no manager is built');
            this.service.enableDrawingMode('polygon');
            assert.ok(true, 'and asking to draw is simply ignored');
        } finally {
            window.google.maps.importLibrary = originalImport;
        }
    });

    test('destroying the map clears every register', async function (assert) {
        await this.service.initializeMap(document.createElement('div'));
        this.service.addPolygon('zone_1', [
            [0, 0],
            [0, 30],
            [30, 30],
        ]);
        this.service.openPopup('popup_1', 1.3, 103.8, 'Depot');
        this.service.addGeoJson('geo_1', { type: 'Point', coordinates: [0, 0] });
        this.service._draftOverlays.add({});
        this.service._selectedOverlay = {};

        this.service.destroyMap();

        assert.strictEqual(this.service._map, null);
        assert.strictEqual(this.service._markers.size, 0);
        assert.strictEqual(this.service._overlays.size, 0);
        assert.strictEqual(this.service._popups.size, 0);
        assert.strictEqual(this.service._geojsonLayers.size, 0);
        assert.strictEqual(this.service._drawingManager, null);
        assert.strictEqual(this.service._selectedOverlay, null);
        assert.strictEqual(this.service._draftOverlays.size, 0);
        assert.false(this.service._supportsAdvancedMarkers, 'and advanced markers are no longer assumed');
    });

    test('advanced markers are used only when the map carries a real map id', async function (assert) {
        await this.service.initializeMap(document.createElement('div'));
        assert.false(this.service._supportsAdvancedMarkers, 'no map id, no advanced markers');

        await this.service.destroyMap();
        await this.service.initializeMap(document.createElement('div'), { mapId: 'FLEETOPS_MAP' });
        assert.false(this.service._supportsAdvancedMarkers, 'the placeholder id does not count');

        await this.service.destroyMap();
        await this.service.initializeMap(document.createElement('div'), { mapId: 'a-real-map-id' });
        assert.true(this.service._supportsAdvancedMarkers, 'a real id turns them on');
    });

    test('initialising again tears the old map down and builds a fresh one', async function (assert) {
        const first = await this.service.initializeMap(document.createElement('div'));
        this.service.addPolygon('zone_1', [
            [0, 0],
            [0, 30],
            [30, 30],
        ]);

        const second = await this.service.initializeMap(document.createElement('div'));

        assert.notStrictEqual(second, first, 'a new map is built rather than the old one returned');
        assert.strictEqual(this.service._overlays.size, 0, 'and the old map takes its overlays with it');
    });

    test('registered markers and overlays are read back by id', function (assert) {
        this.useMap();
        // Both registers are torn down by destroyMap, which detaches whatever it holds.
        const marker = { id: 'marker', setMap() {} };
        const polygon = { id: 'polygon', setMap() {} };

        this.service.registerMarker('marker_1', marker);
        this.service.registerPolygon('zone_1', polygon);

        assert.strictEqual(this.service.getMarker('marker_1'), marker);
        assert.strictEqual(this.service.getOverlay('zone_1'), polygon);
        assert.strictEqual(this.service.getMarker('nope'), null, 'an unknown id answers null');
        assert.strictEqual(this.service.getOverlay('nope'), null);
    });

    test('a right-click reports the point it happened at, and centres the map on it', function (assert) {
        const map = this.useMap();
        const panned = [];
        map.panTo = (center) => panned.push(center);

        assert.deepEqual(this.service.showCoordinates({ latlng: { lat: 1.3, lng: 103.8 } }), { lat: 1.3, lng: 103.8 });
        assert.deepEqual(this.service.showCoordinates({}), { lat: undefined, lng: undefined }, 'an event with no point reports none');
        assert.deepEqual(this.service.showCoordinates(), { lat: undefined, lng: undefined });

        this.service.centerMap({ latlng: { lat: 1.3, lng: 103.8 } });
        assert.deepEqual(panned, [{ lat: 1.3, lng: 103.8 }]);

        this.service.centerMap({ latlng: { lat: 'nowhere', lng: 'nowhere' } });
        this.service.centerMap({});
        this.service.centerMap();
        assert.strictEqual(panned.length, 1, 'nothing without a usable point');

        this.service._map = null;
        this.service.centerMap({ latlng: { lat: 1.3, lng: 103.8 } });
        assert.strictEqual(panned.length, 1, 'nor without a map');
    });

    test('invalidateSize tells Google the map resized', function (assert) {
        const triggered = [];
        const originalTrigger = window.google.maps.event.trigger;
        window.google.maps.event.trigger = (target, event) => triggered.push(event);

        try {
            this.service.invalidateSize();
            assert.deepEqual(triggered, [], 'nothing to resize without a map');

            this.useMap();
            this.service.invalidateSize();
            assert.deepEqual(triggered, ['resize']);
        } finally {
            window.google.maps.event.trigger = originalTrigger;
        }
    });

    test('view settings add and remove the traffic and transit layers', async function (assert) {
        await this.service.applyViewSettings({ showTrafficLayer: true });
        assert.strictEqual(this.trafficLayers.length, 0, 'nothing applies without a map');

        await this.service.initializeMap(document.createElement('div'));
        await this.service.applyViewSettings({ showTrafficLayer: true, showTransitLayer: true });
        assert.strictEqual(this.trafficLayers.length, 1);
        assert.strictEqual(this.transitLayers.length, 1);

        await this.service.applyViewSettings({ showTrafficLayer: true, showTransitLayer: true });
        assert.strictEqual(this.trafficLayers.length, 1, 'asking again reuses the layers already made');
        assert.strictEqual(this.transitLayers.length, 1);

        await this.service.applyViewSettings({});
        assert.deepEqual(this.trafficLayers[0].setMapCalls.at(-1), null, 'and turning them off detaches them');
        assert.deepEqual(this.transitLayers[0].setMapCalls.at(-1), null);

        await this.service.applyViewSettings({});
        assert.strictEqual(this.trafficLayers[0].setMapCalls.length, 3, 'turning off what is already off adds nothing further');
    });

    test('caller styles are appended to the Fleet-Ops base styles', async function (assert) {
        await this.service.initializeMap(document.createElement('div'));

        await this.service.applyViewSettings({ googleOptions: { styles: [{ featureType: 'water', stylers: [] }] } });
        const withCustom = this.map.setOptionsCalls.at(-1).styles;
        assert.strictEqual(withCustom.at(-1).featureType, 'water', 'the caller style goes last');
        assert.true(withCustom.length > 1, 'on top of the base styles');

        await this.service.applyViewSettings({});
        const baseOnly = this.map.setOptionsCalls.at(-1).styles;
        assert.notOk(
            baseOnly.some((style) => style.featureType === 'water'),
            'and no caller styles means the base set alone'
        );
    });

    test('toggleDrawControl builds the toolbar and swaps it in and out', function (assert) {
        this.service.toggleDrawControl();
        assert.strictEqual(this.service._drawControlEl, null, 'there is nothing to toggle without a map');

        this.useMap();
        this.service.toggleDrawControl();
        const toolbar = this.service._drawControlEl;
        assert.ok(toolbar, 'the first toggle builds the toolbar');
        assert.strictEqual(toolbar.style.display, '', 'and shows it');
        assert.deepEqual(
            [...toolbar.__actionGroup.children].map((el) => el.getAttribute('aria-label')),
            ['edit', 'delete'],
            'with the default config, which allows both'
        );

        this.service.toggleDrawControl();
        assert.strictEqual(toolbar.style.display, 'none', 'the second toggle hides it');

        this.service.toggleDrawControl();
        assert.strictEqual(toolbar.style.display, '', 'and the third brings it back');
    });

    test('panBy moves the map by a pixel offset', function (assert) {
        this.service.panBy(10);

        const map = this.useMap();
        const panned = [];
        map.panBy = (x, y) => panned.push([x, y]);

        this.service.panBy(10);
        this.service.panBy(10, 4);

        assert.deepEqual(panned, [
            [10, 0],
            [10, 4],
        ]);
    });

    test('a labelled route marker is drawn as a badge on the advanced path', async function (assert) {
        this.useMap();
        this.service._supportsAdvancedMarkers = true;

        const handle = await this.service.addRoutingControl({ waypoints: [[1.3, 103.8]] }, { id: 'route_1', createMarker: () => ({ waypointLabel: 'P', waypointColor: '#22c55e' }) });
        const badged = this.service._markers.get(handle.markerIds[0]);

        assert.strictEqual(badged.content.className, 'fleetops-map-marker');
        assert.true(badged.content.innerHTML.includes('#22c55e'), 'the badge is drawn in the waypoint colour');
        assert.true(badged.content.innerHTML.includes('P'), 'and carries its label');

        const defaulted = await this.service.addRoutingControl({ waypoints: [[1.3, 103.8]] }, { id: 'route_2', createMarker: () => ({ waypointLabel: '1' }) });
        const blue = this.service._markers.get(defaulted.markerIds[0]);
        assert.true(blue.content.innerHTML.includes('#2563eb'), 'an unstated colour falls back to the route blue');
    });

    test('the Google Maps script is loaded once, and a load failure is reported', async function (assert) {
        const originalGoogle = window.google;
        const originalGlobal = globalThis.google;
        const appended = [];
        const originalAppendChild = document.head.appendChild.bind(document.head);
        document.head.appendChild = (node) => {
            appended.push(node);
            return node;
        };

        try {
            // With the API already on the page the loader resolves without touching the DOM.
            await this.service.initializeMap(document.createElement('div'));
            assert.deepEqual(appended, [], 'an API already present is simply used');

            window.google = undefined;
            globalThis.google = undefined;
            this.service._apiLoaded = false;

            const pending = this.service.initializeMap(document.createElement('div'), { apiKey: 'a-key' });
            const script = appended.at(-1);
            assert.ok(script, 'a script tag is added');
            assert.true(script.src.includes('key=a-key'), 'carrying the key');
            assert.true(script.src.includes('libraries=drawing,geometry,marker,routes'));
            assert.true(script.async);

            script.onerror();
            await assert.rejects(pending, /Failed to load Google Maps API/, 'a script that will not load is reported');
        } finally {
            document.head.appendChild = originalAppendChild;
            window.google = originalGoogle;
            globalThis.google = originalGlobal;
        }
    });
});
