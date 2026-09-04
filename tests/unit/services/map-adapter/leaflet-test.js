import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
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
});
