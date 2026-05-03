/**
 * MapManagerService
 *
 * Provider-agnostic central map service for FleetOps. Replaces the
 * Leaflet-specific `leaflet-map-manager` as the single point of contact
 * for all map operations across the application.
 *
 * The service resolves the correct adapter at initialization time based on
 * the configured `mapProvider` setting (default: 'leaflet'). All method
 * calls are delegated to the active adapter, which translates them into
 * the underlying library's API.
 *
 * Usage:
 *   @service mapManager;
 *   this.mapManager.flyTo(lat, lng, 15);
 *
 * Switching providers:
 *   Set `mapProvider` in config/environment.js or via the settings API.
 *   The adapter is resolved once at `initializeMap` time.
 *
 * @module services/map-manager
 */
import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { isArray } from '@ember/array';
import { inject as service } from '@ember/service';
import { getOwner } from '@ember/application';
import { action } from '@ember/object';
import { debug } from '@ember/debug';
import { next } from '@ember/runloop';

export default class MapManagerService extends Service {
    @service universe;
    @service notifications;
    @service mapSettings;
    @service routeEngine;
    @service leafletContextmenuManager;

    /**
     * The active map provider adapter instance.
     * @type {MapAdapterInterface}
     */
    @tracked adapter = null;

    /**
     * The name of the active provider (e.g. 'leaflet', 'google').
     * @type {string}
     */
    @tracked providerName = 'leaflet';

    /**
     * Reference to the live map component instance.
     * @type {*}
     */
    @tracked _livemap = null;

    /**
     * Whether the map has been fully initialized and is ready for interaction.
     * @type {boolean}
     */
    @tracked isReady = false;
    @tracked routeControls = new Map();

    /**
     * Internal deferred promise that resolves when the map is ready.
     */
    #mapReadyPromise = null;
    #resolveMapReady = null;

    constructor() {
        super(...arguments);
        this.#resetReadyDeferred();
        this.providerName = this.getConfiguredProvider();
    }

    // ─── Provider Resolution ───────────────────────────────────────────────────

    /**
     * Resolve and activate the adapter for the given provider name.
     * Falls back to 'leaflet' if the requested provider is not registered.
     *
     * @param {string} [provider='leaflet']
     * @returns {MapAdapterInterface}
     */
    resolveAdapter(provider = 'leaflet') {
        const owner = getOwner(this);
        const lookupName = `service:map-adapter/${provider}`;
        const previousAdapter = this.adapter;
        let adapter = owner.lookup(lookupName);
        let resolvedProvider = provider;

        if (!adapter) {
            debug(`[MapManager] No adapter found for provider "${provider}", falling back to "leaflet".`);
            adapter = owner.lookup('service:map-adapter/leaflet');
            resolvedProvider = 'leaflet';
        }

        if (!adapter) {
            throw new Error(`[MapManager] Could not resolve any map adapter. Ensure "service:map-adapter/leaflet" is registered.`);
        }

        if (previousAdapter && previousAdapter !== adapter) {
            previousAdapter.destroyMap?.();
        }

        this.adapter = adapter;
        this.providerName = resolvedProvider;
        debug(`[MapManager] Resolved adapter: ${resolvedProvider}`);
        return adapter;
    }

    /**
     * Determine the configured provider from runtime settings.
     *
     * @returns {string}
     */
    getConfiguredProvider() {
        return this.mapSettings.mapProvider ?? 'leaflet';
    }

    // ─── Lifecycle ─────────────────────────────────────────────────────────────

    /**
     * Initialize the map in the given DOM element.
     * Resolves the adapter from config if not already resolved.
     *
     * @param {HTMLElement} element
     * @param {Object} [options={}]
     * @returns {*} The native map instance
     */
    @action initializeMap(element, options = {}) {
        const provider = options.provider ?? this.getConfiguredProvider();

        if (!this.adapter || this.providerName !== provider) {
            this.resolveAdapter(provider);
        }

        const map = this.adapter.initializeMap(element, {
            ...options,
            apiKey: options.apiKey ?? this.mapSettings.googleMapsApiKey,
            mapId: options.mapId ?? this.mapSettings.googleMapsMapId,
        });

        return Promise.resolve(map).then((nativeMap) => {
            this.isReady = true;
            this.#resolveMapReady?.(nativeMap);
            debug('[MapManager] Map initialized');
            return nativeMap;
        });
    }

    /**
     * Destroy the map and reset state.
     */
    @action destroyMap() {
        this.adapter?.destroyMap();
        this.isReady = false;
        this._livemap = null;
        this.#resetReadyDeferred();
        debug('[MapManager] Map destroyed');
    }

    /**
     * Notify the adapter that the container has been resized.
     */
    @action invalidateSize() {
        this.adapter?.invalidateSize();
    }

    /**
     * Set the live map component reference.
     *
     * @param {*} livemap
     */
    @action setLivemap(livemap) {
        this._livemap = livemap;
    }

    /**
     * Wait until the map is fully initialized.
     *
     * @param {Object} [options={}]
     * @param {number} [options.timeoutMs=8000]
     * @returns {Promise<*>}
     */
    waitForMap({ timeoutMs = 8000 } = {}) {
        if (this.isReady && this.adapter?._map) {
            return Promise.resolve(this.adapter._map);
        }

        let to;
        const p = Promise.race([
            this.#mapReadyPromise,
            new Promise((_, rej) => {
                if (timeoutMs != null) {
                    to = setTimeout(() => rej(new Error('[MapManager] waitForMap timed out')), timeoutMs);
                }
            }),
        ]);

        return p.finally(() => {
            if (to) clearTimeout(to);
        });
    }

    async ensureInteractive({ timeoutMs = 8000 } = {}) {
        const map = await this.waitForMap({ timeoutMs });
        if (typeof this.adapter?.ensureInteractive === 'function') {
            return this.adapter.ensureInteractive({ timeoutMs, map });
        }

        return map;
    }

    // ─── Viewport ──────────────────────────────────────────────────────────────

    setCenter(lat, lng, zoom) {
        return this.adapter?.setCenter(lat, lng, zoom);
    }

    flyTo(lat, lng, zoom, options = {}) {
        return this.adapter?.flyTo(lat, lng, zoom, options);
    }

    fitBounds(bounds, options = {}) {
        return this.adapter?.fitBounds(bounds, options);
    }

    panTo(lat, lng, options = {}) {
        return this.adapter?.panTo(lat, lng, options);
    }

    zoomIn() {
        return this.adapter?.zoomIn();
    }

    zoomOut() {
        return this.adapter?.zoomOut();
    }

    getZoom() {
        return this.adapter?.getZoom();
    }

    getCenter() {
        return this.adapter?.getCenter();
    }

    getBounds() {
        return this.adapter?.getBounds();
    }

    get livemap() {
        return this._livemap;
    }

    // ─── Markers ───────────────────────────────────────────────────────────────

    addMarker(id, lat, lng, options = {}) {
        return this.adapter?.addMarker(id, lat, lng, options);
    }

    updateMarkerPosition(id, lat, lng, animated = true, duration = 500) {
        return this.adapter?.updateMarkerPosition(id, lat, lng, animated, duration);
    }

    setMarkerRotation(id, degrees) {
        return this.adapter?.setMarkerRotation(id, degrees);
    }

    removeMarker(id) {
        return this.adapter?.removeMarker(id);
    }

    getMarker(id) {
        return this.adapter?.getMarker(id) ?? null;
    }

    hasMarker(id) {
        return this.adapter?.hasMarker(id) ?? false;
    }

    // ─── Overlays ──────────────────────────────────────────────────────────────

    addPolygon(id, coordinates, options = {}) {
        return this.adapter?.addPolygon(id, coordinates, options);
    }

    addPolyline(id, coordinates, options = {}) {
        return this.adapter?.addPolyline(id, coordinates, options);
    }

    addCircle(id, lat, lng, radiusMeters, options = {}) {
        return this.adapter?.addCircle(id, lat, lng, radiusMeters, options);
    }

    removeOverlay(id) {
        return this.adapter?.removeOverlay(id);
    }

    clearOverlays() {
        return this.adapter?.clearOverlays();
    }

    getOverlay(id) {
        return this.adapter?.getOverlay(id) ?? null;
    }

    // ─── Routing Controls ─────────────────────────────────────────────────────

    async addRoutingControl(waypoints, options = {}) {
        if (!isArray(waypoints) || waypoints.length === 0) return null;

        try {
            await this.ensureInteractive({ timeoutMs: options.timeoutMs ?? 8000 });
            const engine = options.engine ?? this.routeEngine.getDisplayEngine('osrm');
            const route =
                waypoints.length === 1
                    ? {
                          engine: typeof engine === 'string' ? engine : this.routeEngine.getDisplayEngine('osrm'),
                          waypoints,
                          coordinates: [],
                          bounds: null,
                          summary: { totalDistance: 0, totalTime: 0 },
                          legs: [],
                          raw: null,
                      }
                    : await this.routeEngine.compute(engine, waypoints, options);
            const routeControl = await this.adapter?.addRoutingControl?.(route, {
                ...options,
                engine,
            });

            if (routeControl) {
                this.routeControls.set(routeControl.id, routeControl);
            }

            if (options.position !== false) {
                this.positionWaypoints(route.bounds ?? route.waypoints, {
                    ...(options.fitOptions ?? {}),
                    isBounds: Boolean(route.bounds),
                });
            }

            options.onRouteFound?.(route);
            return routeControl;
        } catch (error) {
            options.onRoutingError?.(error);
            debug(`[MapManager] Routing control failed: ${error.message}`);
            return null;
        }
    }

    async replaceRoutingControl(waypoints, existingHandle, options = {}) {
        const removeOptions = options.removeOptions ?? {};
        if (existingHandle) {
            await this.removeRoutingControl(existingHandle, removeOptions);
        }

        return this.addRoutingControl(waypoints, options);
    }

    removeRoutingControl(handle, options = {}) {
        const routeControl = typeof handle === 'string' ? this.routeControls.get(handle) : handle;
        if (!routeControl) return false;

        const removed = this.adapter?.removeRoutingControl?.(routeControl, options) ?? false;
        this.routeControls.delete(routeControl.id);
        return removed;
    }

    clearRoutingControls(filter = null) {
        for (const handle of this.routeControls.values()) {
            if (typeof filter === 'function' && filter(handle) === false) {
                continue;
            }

            this.removeRoutingControl(handle);
        }
    }

    clearRoutingControlsByTag(tag) {
        if (!tag) {
            return;
        }

        this.clearRoutingControls((handle) => handle?.tag === tag);
    }

    positionWaypoints(waypointsOrBounds, options = {}) {
        return this.adapter?.positionWaypoints?.(waypointsOrBounds, options);
    }

    focusResource(record, zoom = 16, options = {}) {
        if (!record) return null;

        const { paddingBottomRight = [0, 0], moveend } = options;
        const layer = record?.leafletLayer ?? this.getMarker(record?.id) ?? this.getOverlay(record?.id);
        const bounds = this.#extractLayerBounds(layer);
        const point = this.#extractLayerPoint(layer) ?? this.#extractRecordPoint(record);

        if (typeof moveend === 'function') {
            this.once('moveend', () => next(this, moveend));
        }

        if (bounds) {
            return this.fitBounds(bounds, { paddingBottomRight, maxZoom: zoom, animate: true });
        }

        if (point) {
            return this.flyTo(point.lat, point.lng, zoom, { animate: true, duration: 0.8 });
        }

        return null;
    }

    // ─── Drawing Tools ─────────────────────────────────────────────────────────

    enableDrawingMode(type, options = {}) {
        return this.adapter?.enableDrawingMode(type, options);
    }

    disableDrawingMode() {
        return this.adapter?.disableDrawingMode();
    }

    showDrawControl(config = {}) {
        return this.adapter?.showDrawControl(config);
    }

    hideDrawControl() {
        return this.adapter?.hideDrawControl();
    }

    // ─── Popups ────────────────────────────────────────────────────────────────

    openPopup(id, lat, lng, htmlContent) {
        return this.adapter?.openPopup(id, lat, lng, htmlContent);
    }

    closePopup(id) {
        return this.adapter?.closePopup(id);
    }

    // ─── Context Menus ─────────────────────────────────────────────────────────

    registerContextMenu(target, items) {
        return this.adapter?.registerContextMenu(target, items);
    }

    removeContextMenu(target) {
        return this.adapter?.removeContextMenu(target);
    }

    // ─── Events ────────────────────────────────────────────────────────────────

    on(event, handler) {
        return this.adapter?.on(event, handler);
    }

    off(event, handler) {
        return this.adapter?.off(event, handler);
    }

    once(event, handler) {
        return this.adapter?.once(event, handler);
    }

    // ─── Utilities ─────────────────────────────────────────────────────────────

    distanceBetween(lat1, lng1, lat2, lng2) {
        return this.adapter?.distanceBetween(lat1, lng1, lat2, lng2);
    }

    addGeoJson(id, geojson, options = {}) {
        return this.adapter?.addGeoJson(id, geojson, options);
    }

    removeGeoJson(id) {
        return this.adapter?.removeGeoJson(id);
    }

    setTileLayer(url, options = {}) {
        return this.adapter?.setTileLayer(url, options);
    }

    // ─── Provider Switching ──────────────────────────────────────────────────────────

    /**
     * Activate a named provider adapter.
     * Called by the live-map component when the map initialises.
     *
     * @param {string} provider  e.g. 'leaflet' | 'google'
     */
    @action setActiveProvider(provider) {
        if (this.providerName === provider && this.adapter) return;
        this.isReady = false;
        this.#resetReadyDeferred();
        this.resolveAdapter(provider);
    }

    /**
     * Bind a host-created native map instance to the active adapter.
     *
     * @param {*} mapInstance
     */
    @action setMapInstance(mapInstance) {
        this.adapter?.setMapInstance?.(mapInstance);
        if (mapInstance) {
            this.isReady = true;
            this.#resolveMapReady?.(mapInstance);
        }
    }

    /**
     * Whether the active provider is Google Maps.
     * Used by templates to conditionally render the correct map branch.
     *
     * @type {boolean}
     */
    get isGoogleMaps() {
        return (this.adapter ? this.providerName : this.getConfiguredProvider()) === 'google';
    }

    #extractLayerBounds(layer) {
        if (!layer) return null;

        if (typeof layer.getBounds === 'function') {
            const bounds = layer.getBounds();

            if (typeof bounds?.getSouth === 'function') {
                return [
                    [bounds.getSouth(), bounds.getWest()],
                    [bounds.getNorth(), bounds.getEast()],
                ];
            }

            if (typeof bounds?.getSouthWest === 'function') {
                const sw = bounds.getSouthWest();
                const ne = bounds.getNorthEast();
                return [
                    [sw.lat(), sw.lng()],
                    [ne.lat(), ne.lng()],
                ];
            }
        }

        return null;
    }

    #extractLayerPoint(layer) {
        if (!layer) return null;

        if (typeof layer.getLatLng === 'function') {
            const latlng = layer.getLatLng();
            if (Number.isFinite(latlng?.lat) && Number.isFinite(latlng?.lng)) {
                return { lat: latlng.lat, lng: latlng.lng };
            }
        }

        const position = layer.position ?? layer.getPosition?.();
        if (position) {
            const lat = typeof position.lat === 'function' ? position.lat() : position.lat;
            const lng = typeof position.lng === 'function' ? position.lng() : position.lng;
            if (Number.isFinite(lat) && Number.isFinite(lng)) {
                return { lat, lng };
            }
        }

        return null;
    }

    #extractRecordPoint(record) {
        const coordinates = record?.location?.coordinates;
        if (isArray(coordinates) && coordinates.length >= 2) {
            const [lng, lat] = coordinates;
            if (Number.isFinite(lat) && Number.isFinite(lng)) {
                return { lat, lng };
            }
        }

        if (Number.isFinite(record?.latitude) && Number.isFinite(record?.longitude)) {
            return { lat: record.latitude, lng: record.longitude };
        }

        return null;
    }

    /**
     * Pass a live `google.maps.Map` instance directly to the Google adapter.
     * Called by `Map::GoogleLiveMap` after the map element is initialised.
     *
     * @param {google.maps.Map} mapInstance
     */
    registerMarker(id, markerObject, meta = {}) {
        return this.adapter?.registerMarker?.(id, markerObject, meta);
    }

    /**
     * Register a native polygon object so that geofence and other services
     * can retrieve it by model id.
     *
     * @param {string} id
     * @param {*} polygonObject  Native polygon (L.Polygon or google.maps.Polygon)
     * @param {Object} [meta={}]
     */
    registerPolygon(id, polygonObject, meta = {}) {
        return this.adapter?.registerPolygon?.(id, polygonObject, meta);
    }

    /**
     * Show a polygon by model id.
     *
     * @param {string} id
     */
    showPolygon(id) {
        return this.adapter?.showPolygon?.(id);
    }

    /**
     * Hide a polygon by model id.
     *
     * @param {string} id
     */
    hidePolygon(id) {
        return this.adapter?.hidePolygon?.(id);
    }

    // ─── Context Menu helpers ─────────────────────────────────────────────────────────

    /**
     * Retrieve the registered context-menu items for a given target key.
     * Used by the Google Maps branch to build floating InfoWindow menus.
     *
     * @param {string} target  e.g. 'map', 'driver:DRV-XXXX'
     * @returns {Array}
     */
    getContextMenuItems(target) {
        const registry = this.leafletContextmenuManager.getRegistry(target);
        const items = registry?.contextmenuItems ?? this.adapter?.getContextMenuItems?.(target) ?? [];

        return items.map((item) => ({
            ...item,
            label: item.label ?? item.text,
            action: item.action ?? item.callback,
        }));
    }

    getComposedContextMenuItems(target) {
        const mapItems = this.getContextMenuItems('map');
        if (!target || target === 'map') {
            return mapItems;
        }

        const targetItems = this.getContextMenuItems(target);
        if (!targetItems.length) {
            return mapItems;
        }

        if (!mapItems.length) {
            return targetItems;
        }

        const combinedItems = [...mapItems];
        const firstTargetItem = targetItems[0];

        if (!firstTargetItem?.separator) {
            combinedItems.push({ separator: true });
        }

        combinedItems.push(...targetItems);
        return combinedItems;
    }

    showContextMenu(event, items = []) {
        return this.adapter?.showContextMenu?.(event, items);
    }

    closeContextMenu() {
        return this.adapter?.closeContextMenu?.();
    }

    // ─── Map control shortcuts ──────────────────────────────────────────────────────

    /**
     * Show the current cursor coordinates in a toast / overlay.
     * Delegates to the active adapter.
     */
    @action showCoordinates(event) {
        const result = this.adapter?.showCoordinates?.(event);
        if (result && typeof result === 'object' && Number.isFinite(result.lat) && Number.isFinite(result.lng)) {
            this.notifications.info(`${result.lat}, ${result.lng}`);
        }

        return result;
    }

    /**
     * Re-centre the map on the user's current location.
     */
    @action centerMap(event) {
        return this.adapter?.centerMap?.(event);
    }

    /**
     * Toggle the drawing toolbar visibility.
     */
    @action toggleDrawControl() {
        return this.adapter?.toggleDrawControl?.();
    }

    /**
     * Pan the map by a pixel offset.
     *
     * @param {number} x  Horizontal pixel offset
     * @param {number} y  Vertical pixel offset (default 0)
     */
    panBy(x, y = 0) {
        return this.adapter?.panBy?.(x, y);
    }

    /**
     * Edit an existing polygon overlay.
     * Delegates to the adapter's `editPolygon` implementation.
     *
     * @param {*} layer  Native polygon object
     * @param {Object} [options={}]
     * @returns {Promise<{ type: 'edited'|'cancel', layer: * }>}
     */
    editPolygon(layer, options = {}) {
        return this.adapter?.editPolygon?.(layer, options) ?? Promise.resolve({ type: 'unsupported' });
    }

    // ─── Layer Visibility (delegated to adapter) ───────────────────────────────

    /**
     * Show a layer by its native object reference.
     * Adapters implement this to show/hide provider-specific overlay objects.
     *
     * @param {*} layer - Native layer object
     * @param {Object} [options={}]
     */
    showLayer(layer, options = {}) {
        return this.adapter?.showLayer?.(layer, options);
    }

    /**
     * Hide a layer by its native object reference.
     *
     * @param {*} layer - Native layer object
     * @param {Object} [options={}]
     */
    hideLayer(layer, options = {}) {
        return this.adapter?.hideLayer?.(layer, options);
    }

    removeLayer(layer) {
        return this.adapter?.removeLayer?.(layer);
    }

    // ─── Private ───────────────────────────────────────────────────────────────

    #resetReadyDeferred() {
        this.#mapReadyPromise = new Promise((resolve) => {
            this.#resolveMapReady = resolve;
        });
    }
}
