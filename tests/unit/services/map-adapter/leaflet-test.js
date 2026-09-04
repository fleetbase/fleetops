import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { settled } from '@ember/test-helpers';
import { resetLeafletPluginLoaderForTesting } from 'dummy/utils/leaflet-plugin-loader';

const L = window.L;

/**
 * leaflet-draw and leaflet-contextmenu are loaded by the host console rather than by this package,
 * so the namespaces they add have to be stubbed onto the real Leaflet the dummy app carries. They
 * are added as own properties and removed again afterwards: replacing `L.Map` or `L.Control`
 * outright would break every later test in the run, since this is the one shared global.
 */
function stubLeafletPlugins() {
    const restore = [];
    const add = (owner, key, value) => {
        const had = Object.prototype.hasOwnProperty.call(owner, key);
        const previous = owner[key];
        owner[key] = value;
        restore.push(() => {
            if (had) {
                owner[key] = previous;
            } else {
                delete owner[key];
            }
        });
    };

    add(L, 'Edit', { ...(L.Edit ?? {}), Marker: function MarkerEdit() {}, Poly: function PolyEdit() {} });
    add(L.Control, 'Draw', function DrawControl() {});
    add(L.Map, 'ContextMenu', function ContextMenu() {});

    return () => restore.forEach((undo) => undo());
}

module('Unit | Service | map-adapter/leaflet', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.restoreLeafletPlugins = stubLeafletPlugins();
        resetLeafletPluginLoaderForTesting();

        this.adapter = this.owner.lookup('service:map-adapter/leaflet');
        this.element = document.createElement('div');
        this.element.style.width = '400px';
        this.element.style.height = '300px';
        document.getElementById('ember-testing').appendChild(this.element);

        // Leaflet's zoom and fade animations run on rAF; a unit test has nothing to settle them
        // against, so the fixture map opts out and every viewport change lands synchronously.
        this.initialize = (options = {}) =>
            this.adapter.initializeMap(this.element, {
                leafletOptions: { zoomAnimation: false, fadeAnimation: false, markerZoomAnimation: false },
                ...options,
            });
    });

    hooks.afterEach(function () {
        this.adapter.destroyMap();
        this.element.remove();
        this.restoreLeafletPlugins();
        resetLeafletPluginLoaderForTesting();
    });

    test('ensureInteractive waits for Leaflet plugin readiness and returns the map', async function (assert) {
        const map = { id: 'leaflet-map' };

        const result = await this.adapter.ensureInteractive({ map, timeoutMs: 1000 });

        assert.strictEqual(result, map);
        assert.true(window.fleetopsLeafletPluginsLoaded);
    });

    test('ensureInteractive falls back to the adapter s own map', async function (assert) {
        const map = this.initialize();

        assert.strictEqual(await this.adapter.ensureInteractive({ timeoutMs: 1000 }), map);
    });

    test('initializeMap centres on Singapore by default and hands back the same map on a second call', function (assert) {
        const map = this.initialize();

        assert.strictEqual(this.adapter.getZoom(), 12, 'the default zoom');
        assert.deepEqual([Math.round(this.adapter.getCenter().lat * 1e4) / 1e4, Math.round(this.adapter.getCenter().lng * 1e4) / 1e4], [1.3521, 103.8198], 'the default centre');
        assert.strictEqual(this.adapter.initializeMap(this.element), map, 'a second call returns the map already made');
    });

    test('initializeMap honours the options it is given and adds a tile layer for a url', function (assert) {
        this.initialize({
            lat: 51.5072,
            lng: -0.1276,
            zoom: 9,
            tileUrl: 'https://tiles.example.test/{z}/{x}/{y}.png',
            tileOptions: { maxZoom: 17 },
        });

        assert.strictEqual(this.adapter.getZoom(), 9);
        assert.strictEqual(Math.round(this.adapter.getCenter().lat * 1e4) / 1e4, 51.5072);
        assert.strictEqual(this.adapter._tileLayer.options.maxZoom, 17, 'the tile options reach the layer');
    });

    test('setMapInstance adopts a map made elsewhere', function (assert) {
        const map = { invalidateSize() {}, remove() {} };

        assert.strictEqual(this.adapter.setMapInstance(map), map);
        assert.strictEqual(this.adapter._map, map);
    });

    test('destroyMap clears every register, and survives a map that refuses to be removed', function (assert) {
        this.initialize();
        this.adapter.addMarker('marker_1', 1.3, 103.8);
        this.adapter.addPolygon('zone_1', [
            [1.3, 103.8],
            [1.4, 103.9],
            [1.5, 103.7],
        ]);

        this.adapter.destroyMap();

        assert.strictEqual(this.adapter._map, null);
        assert.strictEqual(this.adapter._markers.size, 0);
        assert.strictEqual(this.adapter._overlays.size, 0);
        assert.strictEqual(this.adapter._tileLayer, null);

        this.adapter.setMapInstance({
            remove() {
                throw new Error('already gone');
            },
        });
        this.adapter.destroyMap();
        assert.strictEqual(this.adapter._map, null, 'the register is cleared even when removal throws');
    });

    test('invalidateSize is a no-op until there is a map', function (assert) {
        let invalidated = 0;

        this.adapter.invalidateSize();
        this.adapter.setMapInstance({
            invalidateSize() {
                invalidated += 1;
            },
            remove() {},
        });
        this.adapter.invalidateSize();

        assert.strictEqual(invalidated, 1, 'only the call made with a map reaches Leaflet');
    });

    test('every viewport call is a no-op before the map exists', function (assert) {
        assert.strictEqual(this.adapter.setCenter(1, 2, 3), undefined);
        assert.strictEqual(this.adapter.flyTo(1, 2, 3), undefined);
        assert.strictEqual(this.adapter.fitBounds([[1, 2]]), undefined);
        assert.strictEqual(this.adapter.panTo(1, 2), undefined);
        assert.strictEqual(this.adapter.zoomIn(), undefined);
        assert.strictEqual(this.adapter.zoomOut(), undefined);
        assert.strictEqual(this.adapter.getZoom(), 0, 'no map reports zoom zero');
        assert.deepEqual(this.adapter.getCenter(), { lat: 0, lng: 0 });
        assert.deepEqual(this.adapter.getBounds(), [
            [0, 0],
            [0, 0],
        ]);
    });

    test('the viewport moves as asked', function (assert) {
        this.initialize();

        this.adapter.setCenter(40.7128, -74.006, 10);
        assert.strictEqual(this.adapter.getZoom(), 10);
        assert.strictEqual(Math.round(this.adapter.getCenter().lat * 1e4) / 1e4, 40.7128);

        this.adapter.setCenter(48.8566, 2.3522);
        assert.strictEqual(this.adapter.getZoom(), 10, 'setting a centre with no zoom leaves the zoom alone');
        assert.strictEqual(Math.round(this.adapter.getCenter().lng * 1e4) / 1e4, 2.3522);

        this.adapter.panTo(35.6762, 139.6503, { animate: false });
        assert.strictEqual(Math.round(this.adapter.getCenter().lat * 1e4) / 1e4, 35.6762);

        this.adapter.flyTo(-33.8688, 151.2093, 8, { animate: false });
        assert.strictEqual(this.adapter.getZoom(), 8);

        this.adapter.zoomIn();
        assert.strictEqual(this.adapter.getZoom(), 9);
        this.adapter.zoomOut();
        assert.strictEqual(this.adapter.getZoom(), 8);
    });

    test('fitBounds accepts a coordinate pair or a Leaflet bounds object', function (assert) {
        this.initialize();

        this.adapter.fitBounds(
            [
                [1.2, 103.6],
                [1.5, 104.0],
            ],
            { animate: false }
        );
        const fromArray = this.adapter.getBounds();
        assert.true(fromArray[0][0] <= 1.2 && fromArray[1][0] >= 1.5, 'the requested box is inside the resulting view');

        this.adapter.setCenter(0, 0, 2);
        this.adapter.fitBounds(
            L.latLngBounds([
                [1.2, 103.6],
                [1.5, 104.0],
            ]),
            { animate: false }
        );
        assert.deepEqual(this.adapter.getBounds(), fromArray, 'a bounds object lands in the same place as the array');
    });

    test('getCenter reports the origin for a map that cannot answer', function (assert) {
        this.adapter.setMapInstance({
            _loaded: false,
            remove() {},
        });
        assert.deepEqual(this.adapter.getCenter(), { lat: 0, lng: 0 }, 'a map that has not loaded a view');

        this.adapter.setMapInstance({
            _loaded: true,
            getCenter: () => null,
            remove() {},
        });
        assert.deepEqual(this.adapter.getCenter(), { lat: 0, lng: 0 }, 'a map that answers with nothing');

        this.adapter.setMapInstance({
            _loaded: true,
            getCenter() {
                throw new Error('no view');
            },
            remove() {},
        });
        assert.deepEqual(this.adapter.getCenter(), { lat: 0, lng: 0 }, 'a map that throws');
    });

    test('markers are placed, registered and removed', function (assert) {
        assert.strictEqual(this.adapter.addMarker('marker_1', 1.3, 103.8), null, 'nothing is placed without a map');

        this.initialize();
        const clicks = [];
        const marker = this.adapter.addMarker('marker_1', 1.3, 103.8, {
            title: 'Depot',
            alt: 'Depot marker',
            draggable: true,
            zIndexOffset: 400,
            popup: '<b>Depot</b>',
            tooltip: 'Depot',
            onClick: () => clicks.push('clicked'),
        });

        assert.strictEqual(this.adapter._markers.get('marker_1'), marker);
        assert.strictEqual(marker.options.title, 'Depot');
        assert.true(marker.options.draggable);
        assert.strictEqual(marker.options.zIndexOffset, 400);
        assert.ok(marker.getPopup(), 'the popup is bound');
        assert.ok(marker.getTooltip(), 'the tooltip is bound');

        marker.fire('click');
        assert.deepEqual(clicks, ['clicked'], 'the click handler is wired');

        this.adapter.removeMarker('marker_1');
        assert.strictEqual(this.adapter._markers.size, 0);
        this.adapter.removeMarker('marker_1');
        assert.strictEqual(this.adapter._markers.size, 0, 'removing an unknown marker is harmless');
    });

    test('a marker takes an icon url or an icon object', function (assert) {
        this.initialize();

        const fromUrl = this.adapter.addMarker('marker_url', 1.3, 103.8, { iconUrl: '/assets/pin.png' });
        assert.strictEqual(fromUrl.options.icon.options.iconUrl, '/assets/pin.png');
        assert.deepEqual(fromUrl.options.icon.options.iconSize, [24, 24], 'the default size');
        assert.deepEqual(fromUrl.options.icon.options.iconAnchor, [12, 12]);

        const sized = this.adapter.addMarker('marker_sized', 1.3, 103.8, {
            iconUrl: '/assets/pin.png',
            iconSize: [48, 48],
            iconAnchor: [24, 48],
            iconOptions: { className: 'depot-pin' },
        });
        assert.deepEqual(sized.options.icon.options.iconSize, [48, 48]);
        assert.strictEqual(sized.options.icon.options.className, 'depot-pin');

        const icon = L.divIcon({ html: '<span>1</span>' });
        const fromObject = this.adapter.addMarker('marker_object', 1.3, 103.8, { icon });
        assert.strictEqual(fromObject.options.icon, icon);
    });

    test('a marker is moved by sliding when the plugin is loaded, and set outright when it is not', function (assert) {
        this.initialize();
        const marker = this.adapter.addMarker('marker_1', 1.3, 103.8);

        this.adapter.updateMarkerPosition('marker_1', 1.4, 103.9);
        assert.strictEqual(Math.round(marker.getLatLng().lat * 1e4) / 1e4, 1.4, 'without leaflet.marker.slideto the position is set outright');

        const slides = [];
        marker.slideTo = (latlng, options) => slides.push([latlng, options.duration]);
        this.adapter.updateMarkerPosition('marker_1', 1.5, 104.0, true, 750);
        assert.deepEqual(slides, [[[1.5, 104.0], 750]], 'with the plugin the move is animated');

        this.adapter.updateMarkerPosition('marker_1', 1.6, 104.1, false);
        assert.strictEqual(Math.round(marker.getLatLng().lat * 1e4) / 1e4, 1.6, 'an explicit un-animated move still sets it');
        assert.strictEqual(slides.length, 1, 'and does not slide');

        this.adapter.updateMarkerPosition('unknown', 1, 1);
        assert.strictEqual(slides.length, 1, 'moving an unknown marker is harmless');
    });

    test('marker rotation only reaches markers the rotation plugin has extended', function (assert) {
        this.initialize();
        const marker = this.adapter.addMarker('marker_1', 1.3, 103.8);

        this.adapter.setMarkerRotation('marker_1', 90);
        this.adapter.setMarkerRotation('unknown', 90);

        const angles = [];
        marker.setRotationAngle = (degrees) => angles.push(degrees);
        this.adapter.setMarkerRotation('marker_1', 45);

        assert.deepEqual(angles, [45]);
    });

    test('removeMarker and removeOverlay report a failed removal without throwing', function (assert) {
        this.initialize();
        const marker = this.adapter.addMarker('marker_1', 1.3, 103.8);
        marker.remove = () => {
            throw new Error('detached');
        };
        this.adapter.removeMarker('marker_1');
        assert.strictEqual(this.adapter._markers.size, 0, 'the marker is forgotten anyway');

        const polyline = this.adapter.addPolyline('line_1', [
            [1.3, 103.8],
            [1.4, 103.9],
        ]);
        polyline.remove = () => {
            throw new Error('detached');
        };
        this.adapter.removeOverlay('line_1');
        assert.strictEqual(this.adapter._overlays.size, 0);
    });

    test('overlays are drawn with Fleet-Ops defaults and registered by id', function (assert) {
        assert.strictEqual(
            this.adapter.addPolygon('zone_1', [
                [1.3, 103.8],
                [1.4, 103.9],
            ]),
            null,
            'nothing is drawn without a map'
        );
        assert.strictEqual(this.adapter.addPolyline('line_1', []), null);
        assert.strictEqual(this.adapter.addCircle('circle_1', 1.3, 103.8, 100), null);

        this.initialize();

        const polygon = this.adapter.addPolygon(
            'zone_1',
            [
                [1.3, 103.8],
                [1.4, 103.9],
                [1.5, 103.7],
            ],
            { tooltip: 'Zone A' }
        );
        assert.strictEqual(polygon.options.color, '#3388ff', 'the default stroke');
        assert.strictEqual(polygon.options.fillColor, '#3388ff', 'the fill follows the stroke');
        assert.strictEqual(polygon.options.fillOpacity, 0.2);
        assert.strictEqual(polygon.options.weight, 3);
        assert.ok(polygon.getTooltip(), 'the tooltip is bound');
        assert.strictEqual(this.adapter._overlays.get('zone_1'), polygon);

        const styled = this.adapter.addPolygon(
            'zone_2',
            [
                [1.3, 103.8],
                [1.4, 103.9],
                [1.5, 103.7],
            ],
            { color: '#ff0000', fillColor: '#00ff00', fillOpacity: 0.6, weight: 8, leafletOptions: { dashArray: '4' } }
        );
        assert.strictEqual(styled.options.fillColor, '#00ff00');
        assert.strictEqual(styled.options.dashArray, '4', 'raw Leaflet options are passed through');

        const polyline = this.adapter.addPolyline(
            'line_1',
            [
                [1.3, 103.8],
                [1.4, 103.9],
            ],
            { color: '#123456', weight: 5, opacity: 0.5, leafletOptions: { lineCap: 'square' } }
        );
        assert.strictEqual(polyline.options.color, '#123456');
        assert.strictEqual(polyline.options.opacity, 0.5);
        assert.strictEqual(polyline.options.lineCap, 'square');

        const circle = this.adapter.addCircle('circle_1', 1.3, 103.8, 250, { color: '#abcdef', leafletOptions: { interactive: false } });
        assert.strictEqual(circle.getRadius(), 250);
        assert.strictEqual(circle.options.fillColor, '#abcdef', 'the fill follows the given stroke');
        assert.false(circle.options.interactive);

        this.adapter.removeOverlay('zone_1');
        assert.notOk(this.adapter._overlays.has('zone_1'));
        this.adapter.removeOverlay('zone_1');
        assert.notOk(this.adapter._overlays.has('zone_1'), 'removing an unknown overlay is harmless');
    });

    test('distanceBetween measures in metres', function (assert) {
        const metres = this.adapter.distanceBetween(1.3521, 103.8198, 1.3621, 103.8198);

        assert.true(metres > 1050 && metres < 1150, 'a tenth of a degree of latitude is about 1.1km');
    });

    test('geojson layers are added and removed by id', function (assert) {
        this.initialize();
        const geojson = { type: 'Point', coordinates: [103.8198, 1.3521] };

        const layer = this.adapter.addGeoJson('geo_1', geojson, { style: { color: '#ff0000' } });
        assert.strictEqual(this.adapter._geojsonLayers.get('geo_1'), layer);

        this.adapter.removeGeoJson('geo_1');
        assert.strictEqual(this.adapter._geojsonLayers.size, 0);
        this.adapter.removeGeoJson('geo_1');
        assert.strictEqual(this.adapter._geojsonLayers.size, 0, 'removing an unknown layer is harmless');
    });

    test('setTileLayer replaces the layer already on the map', function (assert) {
        this.initialize({ tileUrl: 'https://tiles.example.test/{z}/{x}/{y}.png' });
        const first = this.adapter._tileLayer;

        this.adapter.setTileLayer('https://other.example.test/{z}/{x}/{y}.png', { maxZoom: 15 });

        assert.notStrictEqual(this.adapter._tileLayer, first, 'a new layer takes over');
        assert.strictEqual(this.adapter._tileLayer.options.maxZoom, 15);
    });

    test('a routing control draws one polyline per style and registers its handle', function (assert) {
        assert.strictEqual(this.adapter.addRoutingControl({ waypoints: [[1.3, 103.8]] }), null, 'nothing is drawn without a map');

        this.initialize();
        assert.strictEqual(this.adapter.addRoutingControl(null), null, 'nor without a route');
        assert.strictEqual(this.adapter.addRoutingControl({ waypoints: [] }), null, 'nor for a route with no waypoints');

        const route = {
            engine: 'osrm',
            raw: { source: 'osrm' },
            bounds: [
                [1.3, 103.8],
                [1.4, 103.9],
            ],
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

        const handle = this.adapter.addRoutingControl(route, {
            id: 'route_1',
            tag: 'driver_1',
            polylineOptions: {
                styles: [
                    { color: '#111111', weight: 7, opacity: 0.8, lineCap: 'round', lineJoin: 'round' },
                    { color: '#222222', weight: 3, opacity: 0.9, dashArray: '4' },
                ],
                leafletOptions: { interactive: false },
            },
        });

        assert.strictEqual(handle.id, 'route_1');
        assert.strictEqual(handle.engine, 'osrm');
        assert.strictEqual(handle.tag, 'driver_1');
        assert.strictEqual(handle.raw, route.raw);
        assert.strictEqual(handle.bounds, route.bounds);
        assert.deepEqual(handle.polylineIds, ['route_1:polyline:0', 'route_1:polyline:1'], 'one polyline per style');
        assert.deepEqual(handle.markerIds, ['route_1:marker:0', 'route_1:marker:1'], 'one marker per waypoint');
        assert.strictEqual(this.adapter._routingControls.get('route_1'), handle);

        const under = this.adapter._overlays.get('route_1:polyline:0');
        assert.strictEqual(under.options.color, '#111111');
        assert.strictEqual(under.options.weight, 7);
        assert.strictEqual(under.options.lineCap, 'round');
        assert.false(under.options.interactive, 'the shared leaflet options reach every style');
        assert.strictEqual(this.adapter._overlays.get('route_1:polyline:1').options.dashArray, '4');
    });

    test('a routing control with no styles draws one polyline, defaulted or configured', function (assert) {
        this.initialize();
        const route = {
            waypoints: [[1.3, 103.8]],
            coordinates: [
                [1.3, 103.8],
                [1.4, 103.9],
            ],
        };

        const defaulted = this.adapter.addRoutingControl(route, { id: 'route_default' });
        const line = this.adapter._overlays.get('route_default:polyline:0');
        assert.deepEqual(defaulted.polylineIds, ['route_default:polyline:0']);
        assert.strictEqual(line.options.color, '#2563eb', 'the Fleet-Ops route blue');
        assert.strictEqual(line.options.weight, 4);
        assert.strictEqual(line.options.opacity, 0.85);
        assert.strictEqual(defaulted.tag, null, 'an untagged control carries a null tag');

        this.adapter.addRoutingControl(route, { id: 'route_colored', color: '#ff0000' });
        assert.strictEqual(this.adapter._overlays.get('route_colored:polyline:0').options.color, '#ff0000', 'a bare colour is used when there are no polyline options');

        this.adapter.addRoutingControl(route, {
            id: 'route_styled',
            color: '#ff0000',
            polylineOptions: { color: '#00ff00', weight: 9, opacity: 0.5, leafletOptions: { dashArray: '2' } },
        });
        const styled = this.adapter._overlays.get('route_styled:polyline:0');
        assert.strictEqual(styled.options.color, '#00ff00', 'the polyline options win over the bare colour');
        assert.strictEqual(styled.options.weight, 9);
        assert.strictEqual(styled.options.opacity, 0.5);
        assert.strictEqual(styled.options.dashArray, '2');
    });

    test('a route with no coordinates draws only its markers', function (assert) {
        this.initialize();

        const handle = this.adapter.addRoutingControl({ waypoints: [[1.3, 103.8]] }, { id: 'route_1' });

        assert.deepEqual(handle.polylineIds, [], 'nothing to draw a line from');
        assert.deepEqual(handle.markerIds, ['route_1:marker:0']);
    });

    test('route markers can be suppressed, overridden, built or skipped', function (assert) {
        this.initialize();
        const route = {
            waypoints: [
                [1.3, 103.8],
                [1.4, 103.9],
            ],
        };

        const suppressed = this.adapter.addRoutingControl(route, { id: 'route_bare', suppressMarkers: true });
        assert.deepEqual(suppressed.markerIds, [], 'no markers are placed at all');

        const overridden = this.adapter.addRoutingControl(route, {
            id: 'route_override',
            markerWaypoints: [[1.5, 103.5]],
        });
        assert.deepEqual(overridden.markerIds, ['route_override:marker:0'], 'the given waypoints replace the route s own');
        assert.strictEqual(Math.round(this.adapter._markers.get('route_override:marker:0').getLatLng().lat * 1e4) / 1e4, 1.5);

        const seen = [];
        const skipped = this.adapter.addRoutingControl(route, {
            id: 'route_skip',
            createMarker: (waypoint, index, forRoute) => {
                seen.push([index, forRoute === route]);
                return index === 0 ? null : false;
            },
        });
        assert.deepEqual(seen, [
            [0, true],
            [1, true],
        ]);
        assert.deepEqual(skipped.markerIds, [], 'both null and false skip the marker');

        const built = this.adapter.addRoutingControl(route, {
            id: 'route_built',
            createMarker: (waypoint, index) => ({ title: `Stop ${index + 1}`, draggable: true }),
        });
        assert.deepEqual(built.markerIds, ['route_built:marker:0', 'route_built:marker:1']);
        assert.strictEqual(this.adapter._markers.get('route_built:marker:0').options.title, 'Stop 1', 'a plain object is used as marker options');
    });

    test('a labelled route marker is rendered as a Fleet-Ops waypoint icon', function (assert) {
        this.initialize();
        const route = { waypoints: [[1.3, 103.8]] };

        const labelled = this.adapter.addRoutingControl(route, {
            id: 'route_labelled',
            createMarker: () => ({ waypointLabel: 'P', waypointColor: '#22c55e', title: 'Pickup' }),
        });
        const marker = this.adapter._markers.get(labelled.markerIds[0]);
        assert.strictEqual(marker.options.title, 'Pickup', 'the rest of the custom options survive');
        assert.strictEqual(marker.options.icon.options.className, 'fleetops-waypoint-marker');
        assert.deepEqual(marker.options.icon.options.iconSize, [32, 32], 'the default badge size');
        assert.deepEqual(marker.options.icon.options.iconAnchor, [16, 16]);
        assert.deepEqual(marker.options.icon.options.popupAnchor, [0, -20]);
        assert.true(marker.options.icon.options.html.includes('#22c55e'), 'the badge is drawn in the waypoint colour');

        const sized = this.adapter.addRoutingControl(route, {
            id: 'route_sized',
            createMarker: () => ({ waypointLabel: '1', iconSize: [48, 48], iconAnchor: [24, 24], popupAnchor: [0, -40] }),
        });
        const sizedIcon = this.adapter._markers.get(sized.markerIds[0]).options.icon.options;
        assert.deepEqual(sizedIcon.iconSize, [48, 48]);
        assert.deepEqual(sizedIcon.iconAnchor, [24, 24]);
        assert.deepEqual(sizedIcon.popupAnchor, [0, -40]);
        assert.true(sizedIcon.html.includes('#2563eb'), 'an unstated colour falls back to the route blue');
    });

    test('route markers fall back to the bundled pin, with caller overrides merged in', function (assert) {
        this.initialize();

        const handle = this.adapter.addRoutingControl({ waypoints: [[1.3, 103.8]] }, { id: 'route_1', markerOptions: { title: 'Depot', draggable: true } });
        const marker = this.adapter._markers.get(handle.markerIds[0]);

        assert.strictEqual(marker.options.icon.options.iconUrl, '/assets/images/marker-icon.png');
        assert.deepEqual(marker.options.icon.options.iconSize, [25, 41]);
        assert.deepEqual(marker.options.icon.options.iconAnchor, [12, 41]);
        assert.strictEqual(marker.options.icon.options.shadowUrl, '/assets/images/marker-shadow.png');
        assert.strictEqual(marker.options.title, 'Depot', 'caller marker options are merged over the defaults');
        assert.true(marker.options.draggable);
    });

    test('a routing control without an id is given a unique one', function (assert) {
        this.initialize();
        const route = { waypoints: [[1.3, 103.8]] };

        const first = this.adapter.addRoutingControl(route);
        const second = this.adapter.addRoutingControl(route);

        assert.true(first.id.startsWith('route:'), 'the id names what it is');
        assert.notStrictEqual(first.id, second.id, 'two controls never collide');
        assert.strictEqual(this.adapter._routingControls.size, 2);
    });

    test('removing a routing control takes its markers and lines with it', function (assert) {
        this.initialize();
        const route = {
            waypoints: [[1.3, 103.8]],
            coordinates: [
                [1.3, 103.8],
                [1.4, 103.9],
            ],
        };

        this.adapter.addRoutingControl(route, { id: 'route_1' });
        assert.strictEqual(this.adapter._markers.size, 1);
        assert.strictEqual(this.adapter._overlays.size, 1);

        assert.true(this.adapter.removeRoutingControl('route_1'), 'a control can be removed by id');
        assert.strictEqual(this.adapter._markers.size, 0);
        assert.strictEqual(this.adapter._overlays.size, 0);
        assert.strictEqual(this.adapter._routingControls.size, 0);

        const second = this.adapter.addRoutingControl(route, { id: 'route_2' });
        assert.true(this.adapter.removeRoutingControl(second), 'or by handle');
        assert.strictEqual(this.adapter._routingControls.size, 0);

        assert.false(this.adapter.removeRoutingControl('route_gone'), 'an unknown id reports nothing removed');
        assert.true(this.adapter.removeRoutingControl({ id: 'route_bare' }), 'a handle with no markers or lines is still accepted');
    });

    test('positionWaypoints flies to a lone waypoint and pans once the move ends', function (assert) {
        assert.strictEqual(this.adapter.positionWaypoints([[1.3, 103.8]]), null, 'nothing to position without a map');

        const map = this.initialize();
        const pans = [];
        this.adapter.panBy = (x, y) => pans.push([x, y]);

        assert.true(this.adapter.positionWaypoints([[1.3, 103.8]], { singlePointZoom: 16, panBy: [10, 4] }));
        map.fire('moveend');
        assert.deepEqual(pans, [[10, 4]], 'the pan is deferred until the map settles');

        this.adapter.positionWaypoints([[1.4, 103.9]]);
        map.fire('moveend');
        assert.deepEqual(pans.at(-1), [0, 0], 'with no pan asked for, it pans by nothing');
    });

    test('positionWaypoints fits a box for two or more waypoints, or a bounds object', function (assert) {
        const map = this.initialize();
        const fits = [];
        this.adapter.fitBounds = (bounds, options) => fits.push([bounds, options.maxZoom, options.paddingBottomRight]);
        this.adapter.panBy = () => {};

        assert.true(
            this.adapter.positionWaypoints([
                [1.3, 103.8],
                [1.4, 103.9],
            ])
        );
        assert.strictEqual(fits.at(-1)[1], 15, 'a two-point route is allowed to zoom further in');
        assert.deepEqual(fits.at(-1)[2], [300, 0], 'the default padding leaves room for the overlay');

        this.adapter.positionWaypoints(
            [
                [1.3, 103.8],
                [1.4, 103.9],
                [1.5, 104.0],
            ],
            { maxZoom: 11, paddingBottomRight: [0, 20] }
        );
        assert.strictEqual(fits.at(-1)[1], 11);
        assert.deepEqual(fits.at(-1)[2], [0, 20]);

        this.adapter.positionWaypoints([
            [1.3, 103.8],
            [1.4, 103.9],
            [1.5, 104.0],
        ]);
        assert.strictEqual(fits.at(-1)[1], 14, 'three or more points stay further out by default');

        const bounds = L.latLngBounds([
            [1.2, 103.6],
            [1.6, 104.1],
        ]);
        this.adapter.positionWaypoints(bounds);
        assert.strictEqual(fits.at(-1)[0], bounds, 'a Leaflet bounds object is passed straight through');

        this.adapter.positionWaypoints('anything', { isBounds: true });
        assert.strictEqual(fits.at(-1)[0], 'anything', 'the caller can declare the argument is bounds');

        assert.strictEqual(this.adapter.positionWaypoints([]), null, 'an empty list positions nothing');
        map.fire('moveend');
    });

    test('removeLayer detaches a layer however it can, and forgets a drawn one', function (assert) {
        this.initialize();
        assert.strictEqual(this.adapter.removeLayer(null), undefined, 'nothing to remove');

        let removedSelf = 0;
        this.adapter.removeLayer({
            remove() {
                removedSelf += 1;
            },
        });
        assert.strictEqual(removedSelf, 1, 'a layer that can remove itself does');

        const removedByMap = [];
        const plainLayer = { id: 'plain' };
        this.adapter._map.removeLayer = (layer) => removedByMap.push(layer);
        this.adapter.removeLayer(plainLayer);
        assert.deepEqual(removedByMap, [plainLayer], 'otherwise the map is asked to remove it');

        this.adapter.removeLayer({
            remove() {
                throw new Error('already detached');
            },
        });
        assert.ok(true, 'a layer that refuses to be removed does not take the caller down');

        const drawn = { remove() {} };
        const dropped = [];
        this.adapter._drawFeatureGroup = {
            hasLayer: (layer) => layer === drawn,
            removeLayer: (layer) => dropped.push(layer),
        };
        this.adapter.removeLayer(drawn);
        assert.deepEqual(dropped, [drawn], 'a drawn layer also leaves the draw group');

        this.adapter.removeLayer({ remove() {} });
        assert.strictEqual(dropped.length, 1, 'a layer the group does not hold is left alone');
    });

    test('hiding and showing a layer softly changes only its paint', function (assert) {
        this.initialize();
        const styles = [];
        const shape = {
            options: { fillOpacity: 0.4, fill: true },
            setStyle: (style) => styles.push(style),
        };

        this.adapter.hideLayer(shape, { soft: true });
        assert.deepEqual(styles.at(-1), { opacity: 0, fillOpacity: 0 });
        assert.true(shape.__hidden);
        assert.true(this.adapter.isLayerHidden(shape));

        this.adapter.showLayer(shape, { soft: true });
        assert.deepEqual(styles.at(-1), { opacity: 1, fillOpacity: 0.4 }, 'the layer s own fill opacity is restored');
        assert.false(shape.__hidden);
        assert.true(this.adapter.isLayerVisible(shape));

        const unfilled = { options: { fill: null }, setStyle: (style) => styles.push(style) };
        this.adapter.showLayer(unfilled, { soft: true });
        assert.deepEqual(styles.at(-1), { opacity: 1, fillOpacity: 0 }, 'a layer with no fill is not given one');

        const opacities = [];
        const marker = { setOpacity: (value) => opacities.push(value) };
        this.adapter.hideLayer(marker, { soft: true });
        this.adapter.showLayer(marker, { soft: true });
        assert.deepEqual(opacities, [0, 1], 'a layer that only knows opacity is faded instead');
    });

    test('hiding a layer outright also hides its tooltip and popup, and showing it brings them back', async function (assert) {
        this.initialize();
        const icon = document.createElement('div');
        const tooltipContainer = document.createElement('div');
        const popupContainer = document.createElement('div');
        const calls = [];
        const layer = {
            _icon: icon,
            getTooltip: () => ({ _container: tooltipContainer, isOpen: () => true, options: {} }),
            getPopup: () => ({ _container: popupContainer, isOpen: () => true }),
            closeTooltip: () => calls.push('closeTooltip'),
            closePopup: () => calls.push('closePopup'),
            openTooltip: () => calls.push('openTooltip'),
            openPopup: () => calls.push('openPopup'),
        };

        this.adapter.hideLayer(layer);
        assert.strictEqual(icon.style.display, 'none');
        assert.true(this.adapter.isLayerHidden(layer));

        await settled();
        assert.deepEqual(calls, ['closeTooltip', 'closePopup'], 'both overlays are closed on the next turn');
        assert.strictEqual(tooltipContainer.style.display, 'none');
        assert.strictEqual(popupContainer.style.display, 'none');
        assert.true(layer.__hadOpenTooltip, 'it remembers they were open');
        assert.true(layer.__hadOpenPopup);

        this.adapter.showLayer(layer);
        assert.strictEqual(icon.style.display, '');
        assert.strictEqual(tooltipContainer.style.display, '');
        assert.strictEqual(popupContainer.style.display, '');
        assert.deepEqual(calls, ['closeTooltip', 'closePopup', 'openTooltip', 'openPopup'], 'and reopens them');
        assert.false('__hadOpenTooltip' in layer, 'the memory is cleared once used');
        assert.false('__hadOpenPopup' in layer);
    });

    test('a permanent tooltip reopens even if it was closed, and overlay errors are swallowed', async function (assert) {
        this.initialize();
        const calls = [];
        const permanent = {
            _path: document.createElement('div'),
            _tooltip: { options: { permanent: true } },
            openTooltip: () => calls.push('openTooltip'),
        };

        this.adapter.showLayer(permanent);
        assert.deepEqual(calls, ['openTooltip'], 'a permanent tooltip is always put back');

        const angry = {
            _container: document.createElement('div'),
            _tooltip: { isOpen: () => true, options: {} },
            _popup: { isOpen: () => true },
            closeTooltip() {
                throw new Error('no tooltip');
            },
            closePopup() {
                throw new Error('no popup');
            },
            openTooltip() {
                throw new Error('no tooltip');
            },
            openPopup() {
                throw new Error('no popup');
            },
        };
        this.adapter.hideLayer(angry);
        await settled();
        angry.__hadOpenTooltip = true;
        angry.__hadOpenPopup = true;
        this.adapter.showLayer(angry);
        assert.ok(true, 'a layer whose overlays throw on both sides is survived');
    });

    test('layer visibility is a no-op without a map or a layer, and an unknown layer reads as visible', function (assert) {
        const layer = { _icon: document.createElement('div') };

        this.adapter.showLayer(layer);
        this.adapter.hideLayer(layer);
        assert.strictEqual(layer._icon.style.display, '', 'nothing happens before there is a map');

        this.initialize();
        this.adapter.showLayer(null);
        this.adapter.hideLayer(null);
        assert.ok(true, 'and nothing happens without a layer');

        assert.true(this.adapter.isLayerHidden(null), 'a layer that is not there counts as hidden');
        assert.false(this.adapter.isLayerHidden({}), 'a layer with nothing on the page counts as visible');
        assert.true(this.adapter.isLayerVisible({}));
    });
});
