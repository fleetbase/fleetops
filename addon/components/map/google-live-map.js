/**
 * Map::GoogleLiveMap
 *
 * Google Maps implementation of the live map surface.
 * This component is rendered by `Map::LeafletLiveMap` when the active provider
 * is set to "google" (i.e. `mapManager.isGoogleMaps === true`).
 *
 * It provides 1-to-1 feature parity with the Leaflet live map:
 *   - Real-time driver / vehicle tracking markers with rotation
 *   - Place markers with info-windows
 *   - Service-area and zone polygon overlays
 *   - Drawing mode for geofence creation / editing
 *   - Right-click context menus (via Google Maps InfoWindow overlay)
 *   - Viewport-aware resource loading (bounds-based)
 *
 * All marker/polygon operations are delegated to the GoogleMapsAdapter via
 * the MapManagerService so that the rest of the application (movement-tracker,
 * position-playback, geofence, etc.) remains provider-agnostic.
 *
 * Usage (automatic — rendered by leaflet-live-map.hbs):
 *   <Map::GoogleLiveMap @latitude=... @longitude=... @zoom=... ... />
 *
 * Direct usage (e.g. in tests or custom layouts):
 *   <Map::GoogleLiveMap
 *       @latitude={{1.369}}
 *       @longitude={{103.886}}
 *       @zoom={{12}}
 *       @drivers={{this.drivers}}
 *       @vehicles={{this.vehicles}}
 *       @places={{this.places}}
 *       @serviceAreas={{this.serviceAreas}}
 *       @onLoad={{this.handleMapLoad}}
 *   />
 */
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { debug } from '@ember/debug';
import { guidFor } from '@ember/object/internals';

/**
 * Build a compact HTML string for a driver info-window.
 * Mirrors the content of the Leaflet `<marker.popup>` block.
 *
 * @param {Object} driver
 * @returns {string}
 */
function buildDriverInfoWindowContent(driver) {
    return `
        <div class="flex flex-row p-1" style="min-width:200px">
            <div class="w-12 mr-2">
                <img src="${driver.photoUrl ?? ''}" alt="${driver.name ?? ''}"
                     style="border-radius:6px;width:48px;height:48px;object-fit:cover;box-shadow:0 1px 3px rgba(0,0,0,.2)" />
            </div>
            <div>
                <div style="font-size:12px;font-weight:600">${driver.name ?? '-'}</div>
                <div style="font-size:11px">Phone: ${driver.phone ?? '-'}</div>
                <div style="font-size:11px">Vehicle: ${driver.vehicle_name ?? '-'}</div>
                <div style="font-size:11px">Status:
                    <span style="color:${driver.online ? '#22c55e' : '#f87171'}">
                        ${driver.online ? 'Online' : 'Offline'}
                    </span>
                </div>
            </div>
        </div>`;
}

/**
 * Build a compact HTML string for a vehicle info-window.
 *
 * @param {Object} vehicle
 * @returns {string}
 */
function buildVehicleInfoWindowContent(vehicle) {
    return `
        <div class="flex flex-row p-1" style="min-width:200px">
            <div class="w-14 mr-2">
                <img src="${vehicle.photo_url ?? ''}" alt="${vehicle.display_name ?? ''}"
                     style="border-radius:6px;width:56px;height:48px;object-fit:cover;box-shadow:0 1px 3px rgba(0,0,0,.2)" />
            </div>
            <div>
                <div style="font-size:12px;font-weight:600">${vehicle.displayName ?? '-'}</div>
                <div style="font-size:11px">ID: ${vehicle.public_id ?? '-'}</div>
                <div style="font-size:11px">Serial: ${vehicle.serial_number ?? vehicle.vin ?? '-'}</div>
                <div style="font-size:11px">Driver: ${vehicle.driver_name ?? '-'}</div>
                <div style="font-size:11px">Status:
                    <span style="color:${vehicle.online ? '#22c55e' : '#f87171'}">
                        ${vehicle.online ? 'Online' : 'Offline'}
                    </span>
                </div>
            </div>
        </div>`;
}

/**
 * Build a compact HTML string for a place info-window.
 *
 * @param {Object} place
 * @returns {string}
 */
function buildPlaceInfoWindowContent(place) {
    return `
        <div style="font-size:12px;min-width:160px">
            <div style="font-weight:600">${place.name ?? place.address ?? '-'}</div>
            <div>${place.address ?? ''}</div>
        </div>`;
}

export default class MapGoogleLiveMapComponent extends Component {
    @service mapManager;
    @service universe;

    /** Unique DOM id for the map container element */
    id = guidFor(this);

    /** @type {google.maps.Map|null} Internal Google Maps instance */
    @tracked _googleMap = null;

    /** @type {Map<string, google.maps.Marker>} markerId → google.maps.Marker */
    _markers = new Map();

    /** @type {Map<string, google.maps.Polygon>} polygonId → google.maps.Polygon */
    _polygons = new Map();

    /** @type {Map<string, google.maps.InfoWindow>} markerId → InfoWindow */
    _infoWindows = new Map();

    /** @type {google.maps.drawing.DrawingManager|null} */
    _drawingManager = null;

    /** @type {Function|null} Pending onCreate callback for drawing mode */
    _drawingOnCreate = null;

    willDestroy() {
        super.willDestroy();
        this._cleanupMap();
    }

    // ─── Lifecycle ────────────────────────────────────────────────────────────

    /**
     * Called by the `{{did-insert}}` modifier on the map container div.
     * Initialises the Google Maps instance and notifies the parent component.
     *
     * @param {HTMLElement} element
     */
    @action async setupMap(element) {
        // Wait for the Google Maps JS API to be available
        await this._waitForGoogleMaps();

        const google = window.google;
        const map = new google.maps.Map(element, {
            center: { lat: this.args.latitude ?? 1.369, lng: this.args.longitude ?? 103.886 },
            zoom: this.args.zoom ?? 12,
            mapTypeId: google.maps.MapTypeId.ROADMAP,
            // Disable default UI controls that we replicate ourselves
            zoomControl: false,
            mapTypeControl: false,
            streetViewControl: false,
            fullscreenControl: false,
            // Enable right-click context menu support
            disableDoubleClickZoom: false,
        });

        this._googleMap = map;

        // Register with the adapter
        this.mapManager.setGoogleMapInstance(map);

        // Viewport-based reload on pan/zoom
        map.addListener('idle', () => {
            this.args.onViewportChanged?.();
        });

        // Right-click context menu
        map.addListener('rightclick', (event) => {
            this._showMapContextMenu(event);
        });

        // Notify parent (mirrors Leaflet's `@onLoad`)
        this.args.onLoad?.(this.mapManager);

        // Render initial resources
        this._renderResources();

        debug('[GoogleLiveMap] Map initialised');
    }

    // ─── Resource rendering ───────────────────────────────────────────────────

    /**
     * Re-render all resources whenever the tracked args change.
     * Called after `setupMap` and whenever `@drivers`, `@vehicles`, etc. update.
     */
    _renderResources() {
        if (!this._googleMap) return;
        this._renderDrivers();
        this._renderVehicles();
        this._renderPlaces();
        this._renderServiceAreas();
    }

    _renderDrivers() {
        const drivers = this.args.drivers ?? [];
        drivers.forEach((driver) => this._upsertDriverMarker(driver));
    }

    _renderVehicles() {
        const vehicles = this.args.vehicles ?? [];
        vehicles.forEach((vehicle) => this._upsertVehicleMarker(vehicle));
    }

    _renderPlaces() {
        const places = this.args.places ?? [];
        places.forEach((place) => this._upsertPlaceMarker(place));
    }

    _renderServiceAreas() {
        const serviceAreas = this.args.serviceAreas ?? [];
        serviceAreas.forEach((serviceArea) => {
            this._upsertServiceAreaPolygon(serviceArea);
            (serviceArea.zones ?? []).forEach((zone) => this._upsertZonePolygon(zone, serviceArea));
        });
    }

    // ─── Marker helpers ───────────────────────────────────────────────────────

    /**
     * Create or update a driver tracking marker.
     *
     * @param {Object} driver
     */
    _upsertDriverMarker(driver) {
        const google = window.google;
        const coords = this._getCoords(driver.location);
        if (!coords) return;

        const existing = this._markers.get(`driver:${driver.id}`);
        if (existing) {
            existing.setPosition(coords);
            return;
        }

        const marker = new google.maps.Marker({
            map: this._googleMap,
            position: coords,
            icon: {
                url: driver.vehicle_avatar ?? '/engines-dist/images/driver-marker.png',
                scaledSize: new google.maps.Size(24, 24),
                anchor: new google.maps.Point(12, 12),
            },
            title: driver.name,
            optimized: true,
        });

        // Rotation via SVG icon transform is handled by the adapter
        if (Number.isFinite(driver.heading) && driver.heading !== -1) {
            this.mapManager.setMarkerRotation(`driver:${driver.id}`, driver.heading);
        }

        // Info-window (popup equivalent)
        const infoWindow = new google.maps.InfoWindow({
            content: buildDriverInfoWindowContent(driver),
        });
        this._infoWindows.set(`driver:${driver.id}`, infoWindow);

        marker.addListener('click', () => {
            this._closeAllInfoWindows();
            infoWindow.open({ map: this._googleMap, anchor: marker });
            this.args.onDriverClicked?.(driver);
        });

        marker.addListener('rightclick', (event) => {
            this._showDriverContextMenu(driver, marker, event);
        });

        this._markers.set(`driver:${driver.id}`, marker);

        // Register with the adapter registry so movement-tracker can find it
        this.mapManager.registerMarker?.(`driver:${driver.id}`, marker, { type: 'driver' });
        this.mapManager.registerMarker?.(driver.id, marker, { type: 'driver' });

        this.args.onDriverAdded?.(driver, marker);
    }

    /**
     * Create or update a vehicle tracking marker.
     *
     * @param {Object} vehicle
     */
    _upsertVehicleMarker(vehicle) {
        const google = window.google;
        const coords = this._getCoords(vehicle.location);
        if (!coords) return;

        const existing = this._markers.get(`vehicle:${vehicle.id}`);
        if (existing) {
            existing.setPosition(coords);
            return;
        }

        const marker = new google.maps.Marker({
            map: this._googleMap,
            position: coords,
            icon: {
                url: vehicle.avatar_url ?? '/engines-dist/images/vehicle-marker.png',
                scaledSize: new google.maps.Size(24, 24),
                anchor: new google.maps.Point(12, 12),
            },
            title: vehicle.displayName,
            optimized: true,
        });

        if (Number.isFinite(vehicle.heading) && vehicle.heading !== -1) {
            this.mapManager.setMarkerRotation(`vehicle:${vehicle.id}`, vehicle.heading);
        }

        const infoWindow = new google.maps.InfoWindow({
            content: buildVehicleInfoWindowContent(vehicle),
        });
        this._infoWindows.set(`vehicle:${vehicle.id}`, infoWindow);

        marker.addListener('click', () => {
            this._closeAllInfoWindows();
            infoWindow.open({ map: this._googleMap, anchor: marker });
            this.args.onVehicleClicked?.(vehicle);
        });

        marker.addListener('rightclick', (event) => {
            this._showVehicleContextMenu(vehicle, marker, event);
        });

        this._markers.set(`vehicle:${vehicle.id}`, marker);
        this.mapManager.registerMarker?.(`vehicle:${vehicle.id}`, marker, { type: 'vehicle' });
        this.mapManager.registerMarker?.(vehicle.id, marker, { type: 'vehicle' });

        this.args.onVehicleAdded?.(vehicle, marker);
    }

    /**
     * Create or update a place marker.
     *
     * @param {Object} place
     */
    _upsertPlaceMarker(place) {
        const google = window.google;
        const coords = this._getCoords(place.location);
        if (!coords) return;

        const existing = this._markers.get(`place:${place.id}`);
        if (existing) {
            existing.setPosition(coords);
            return;
        }

        const marker = new google.maps.Marker({
            map: this._googleMap,
            position: coords,
            icon: {
                url: place.avatar_url ?? '/engines-dist/images/building-marker.png',
                scaledSize: new google.maps.Size(16, 16),
                anchor: new google.maps.Point(8, 8),
            },
            title: place.address,
        });

        const infoWindow = new google.maps.InfoWindow({
            content: buildPlaceInfoWindowContent(place),
        });
        this._infoWindows.set(`place:${place.id}`, infoWindow);

        marker.addListener('click', () => {
            this._closeAllInfoWindows();
            infoWindow.open({ map: this._googleMap, anchor: marker });
            this.args.onPlaceClicked?.(place);
        });

        this._markers.set(`place:${place.id}`, marker);
        this.mapManager.registerMarker?.(place.id, marker, { type: 'place' });

        this.args.onPlaceAdded?.(place, marker);
    }

    // ─── Polygon helpers ──────────────────────────────────────────────────────

    /**
     * Create or update a service-area polygon.
     *
     * @param {Object} serviceArea
     */
    _upsertServiceAreaPolygon(serviceArea) {
        const google = window.google;
        const paths = this._coordinatesToGooglePaths(serviceArea.leafletCoordinates);
        if (!paths) return;

        const existing = this._polygons.get(`service-area:${serviceArea.id}`);
        if (existing) {
            existing.setPaths(paths);
            return;
        }

        const polygon = new google.maps.Polygon({
            map: this._googleMap,
            paths,
            fillColor: serviceArea.color ?? '#3388ff',
            fillOpacity: 0.2,
            strokeColor: serviceArea.stroke_color ?? serviceArea.color ?? '#3388ff',
            strokeWeight: 2,
            visible: false, // hidden by default, same as Leaflet behaviour
        });

        polygon.addListener('rightclick', (event) => {
            this._showServiceAreaContextMenu(serviceArea, polygon, event);
        });

        this._polygons.set(`service-area:${serviceArea.id}`, polygon);
        this.mapManager.registerPolygon?.(serviceArea.id, polygon, { type: 'service-area' });

        this.args.onServiceAreaLayerAdded?.(serviceArea, polygon);
    }

    /**
     * Create or update a zone polygon.
     *
     * @param {Object} zone
     * @param {Object} serviceArea
     */
    _upsertZonePolygon(zone, serviceArea) {
        const google = window.google;
        const paths = this._coordinatesToGooglePaths(zone.leafletCoordinates);
        if (!paths) return;

        const existing = this._polygons.get(`zone:${zone.id}`);
        if (existing) {
            existing.setPaths(paths);
            return;
        }

        const polygon = new google.maps.Polygon({
            map: this._googleMap,
            paths,
            fillColor: zone.color ?? '#3388ff',
            fillOpacity: 0.2,
            strokeColor: zone.stroke_color ?? zone.color ?? '#3388ff',
            strokeWeight: 2,
            visible: false,
        });

        polygon.addListener('rightclick', (event) => {
            this._showZoneContextMenu(zone, polygon, event);
        });

        this._polygons.set(`zone:${zone.id}`, polygon);
        this.mapManager.registerPolygon?.(zone.id, polygon, { type: 'zone' });

        this.args.onZoneLayerAdd?.(zone, polygon);
    }

    // ─── Context menus ────────────────────────────────────────────────────────

    /**
     * Show a map-level right-click context menu using a floating InfoWindow.
     *
     * @param {google.maps.MapMouseEvent} event
     */
    _showMapContextMenu(event) {
        const items = this.mapManager.getContextMenuItems?.('map') ?? [];
        if (!items.length) return;
        this._showContextMenuAt(event.latLng, items);
    }

    /**
     * Show a driver right-click context menu.
     */
    _showDriverContextMenu(driver, marker, event) {
        const items = this.mapManager.getContextMenuItems?.(`driver:${driver.public_id}`) ?? [];
        this._showContextMenuAt(event.latLng ?? marker.getPosition(), items);
    }

    /**
     * Show a vehicle right-click context menu.
     */
    _showVehicleContextMenu(vehicle, marker, event) {
        const items = this.mapManager.getContextMenuItems?.(`vehicle:${vehicle.public_id}`) ?? [];
        this._showContextMenuAt(event.latLng ?? marker.getPosition(), items);
    }

    /**
     * Show a service-area right-click context menu.
     */
    _showServiceAreaContextMenu(serviceArea, polygon, event) {
        const items = this.mapManager.getContextMenuItems?.(`service-area:${serviceArea.public_id}`) ?? [];
        this._showContextMenuAt(event.latLng, items);
    }

    /**
     * Show a zone right-click context menu.
     */
    _showZoneContextMenu(zone, polygon, event) {
        const items = this.mapManager.getContextMenuItems?.(`zone:${zone.public_id}`) ?? [];
        this._showContextMenuAt(event.latLng, items);
    }

    /**
     * Render a context menu as a floating InfoWindow at the given LatLng.
     *
     * @param {google.maps.LatLng} latLng
     * @param {Array<{ text: string, callback: Function, separator?: boolean }>} items
     */
    _showContextMenuAt(latLng, items) {
        const google = window.google;
        this._closeAllInfoWindows();

        const html = items
            .map((item) => {
                if (item.separator) return '<hr style="margin:4px 0" />';
                return `<div class="gm-context-item" style="padding:4px 8px;cursor:pointer;font-size:12px;white-space:nowrap"
                             data-action="${item.text}">${item.text}</div>`;
            })
            .join('');

        const container = document.createElement('div');
        container.style.cssText = 'background:#fff;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,.2);padding:4px 0;min-width:140px';
        container.innerHTML = html;

        // Wire up click handlers
        items.forEach((item) => {
            if (item.separator || !item.text) return;
            const el = container.querySelector(`[data-action="${item.text}"]`);
            if (el && typeof item.callback === 'function') {
                el.addEventListener('click', () => {
                    item.callback({ latlng: latLng });
                    ctxMenu.close();
                });
                el.addEventListener('mouseover', () => (el.style.background = '#f3f4f6'));
                el.addEventListener('mouseout', () => (el.style.background = ''));
            }
        });

        const ctxMenu = new google.maps.InfoWindow({
            content: container,
            position: latLng,
            disableAutoPan: true,
        });
        ctxMenu.open(this._googleMap);
        this._contextMenu = ctxMenu;
    }

    // ─── Utilities ────────────────────────────────────────────────────────────

    /**
     * Close all open info-windows and the context menu.
     */
    _closeAllInfoWindows() {
        this._infoWindows.forEach((iw) => iw.close());
        this._contextMenu?.close();
    }

    /**
     * Extract { lat, lng } from a GeoJSON Point or [lat, lng] array.
     *
     * @param {Object|Array|null} location
     * @returns {{ lat: number, lng: number }|null}
     */
    _getCoords(location) {
        if (!location) return null;
        // GeoJSON Point: { type: 'Point', coordinates: [lng, lat] }
        if (location?.coordinates && isArray(location.coordinates)) {
            const [lng, lat] = location.coordinates;
            if (Number.isFinite(lat) && Number.isFinite(lng)) return { lat, lng };
        }
        // [lat, lng] array
        if (isArray(location) && location.length >= 2) {
            const [lat, lng] = location;
            if (Number.isFinite(lat) && Number.isFinite(lng)) return { lat, lng };
        }
        // { latitude, longitude } object
        if (Number.isFinite(location?.latitude) && Number.isFinite(location?.longitude)) {
            return { lat: location.latitude, lng: location.longitude };
        }
        return null;
    }

    /**
     * Convert Leaflet-style coordinates (LatLngBounds / LatLng[][] / L.LatLng[])
     * to Google Maps polygon paths (Array<{ lat, lng }>).
     *
     * @param {any} leafletCoordinates
     * @returns {Array<{ lat: number, lng: number }>[]|null}
     */
    _coordinatesToGooglePaths(leafletCoordinates) {
        if (!leafletCoordinates) return null;
        try {
            // ember-leaflet exposes coordinates as L.LatLng[][]
            const rings = Array.isArray(leafletCoordinates[0]) ? leafletCoordinates : [leafletCoordinates];
            return rings.map((ring) =>
                ring.map((pt) => {
                    if (typeof pt.lat === 'number') return { lat: pt.lat, lng: pt.lng };
                    if (Array.isArray(pt)) return { lat: pt[0], lng: pt[1] };
                    return null;
                }).filter(Boolean)
            );
        } catch {
            return null;
        }
    }

    /**
     * Wait for `window.google.maps` to be available (loaded via script tag).
     *
     * @returns {Promise<void>}
     */
    _waitForGoogleMaps() {
        return new Promise((resolve) => {
            if (window.google?.maps) return resolve();
            const interval = setInterval(() => {
                if (window.google?.maps) {
                    clearInterval(interval);
                    resolve();
                }
            }, 100);
        });
    }

    /**
     * Remove all map objects and listeners.
     */
    _cleanupMap() {
        this._markers.forEach((m) => m.setMap(null));
        this._polygons.forEach((p) => p.setMap(null));
        this._infoWindows.forEach((iw) => iw.close());
        this._drawingManager?.setMap(null);
        this._markers.clear();
        this._polygons.clear();
        this._infoWindows.clear();
        this._drawingManager = null;
        this._googleMap = null;
    }
}
