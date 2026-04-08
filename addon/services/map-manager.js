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
import { inject as service } from '@ember/service';
import { getOwner } from '@ember/application';
import { action } from '@ember/object';
import { debug } from '@ember/debug';

export default class MapManagerService extends Service {
    @service universe;
    @service notifications;

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

    /**
     * Internal deferred promise that resolves when the map is ready.
     */
    #mapReadyPromise = null;
    #resolveMapReady = null;

    constructor() {
        super(...arguments);
        this.#resetReadyDeferred();
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
        let adapter = owner.lookup(lookupName);

        if (!adapter) {
            debug(`[MapManager] No adapter found for provider "${provider}", falling back to "leaflet".`);
            adapter = owner.lookup('service:map-adapter/leaflet');
        }

        if (!adapter) {
            throw new Error(`[MapManager] Could not resolve any map adapter. Ensure "service:map-adapter/leaflet" is registered.`);
        }

        this.adapter = adapter;
        this.providerName = provider;
        debug(`[MapManager] Resolved adapter: ${provider}`);
        return adapter;
    }

    /**
     * Determine the configured provider from the environment config.
     *
     * @returns {string}
     */
    getConfiguredProvider() {
        const owner = getOwner(this);
        const config = owner.resolveRegistration('config:environment');
        return config?.['@fleetbase/fleetops-engine']?.mapProvider ?? config?.mapProvider ?? 'leaflet';
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
        if (!this.adapter) {
            const provider = this.getConfiguredProvider();
            this.resolveAdapter(provider);
        }

        const map = this.adapter.initializeMap(element, options);
        this.isReady = true;
        this.#resolveMapReady?.(map);
        debug('[MapManager] Map initialized');
        return map;
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

    // ─── Drawing Tools ─────────────────────────────────────────────────────────

    enableDrawingMode(type) {
        return this.adapter?.enableDrawingMode(type);
    }

    disableDrawingMode() {
        return this.adapter?.disableDrawingMode();
    }

    showDrawControl() {
        return this.adapter?.showDrawControl();
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
        this.resolveAdapter(provider);
    }

    /**
     * Whether the active provider is Google Maps.
     * Used by templates to conditionally render the correct map branch.
     *
     * @type {boolean}
     */
    get isGoogleMaps() {
        return this.providerName === 'google';
    }

    /**
     * Pass a live `google.maps.Map` instance directly to the Google adapter.
     * Called by `Map::GoogleLiveMap` after the map element is initialised.
     *
     * @param {google.maps.Map} mapInstance
     */
    setGoogleMapInstance(mapInstance) {
        if (this.adapter?.setMapInstance) {
            this.adapter.setMapInstance(mapInstance);
        }
        this.isReady = true;
        this.#resolveMapReady?.(mapInstance);
    }

    // ─── Registry helpers (used by live-map component) ───────────────────────────────

    /**
     * Register a native marker object so that movement-tracker and other
     * services can retrieve it by model id.
     *
     * @param {string} id
     * @param {*} markerObject  Native marker (L.Marker or google.maps.Marker)
     * @param {Object} [meta={}]
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
        return this.adapter?.getContextMenuItems?.(target) ?? [];
    }

    // ─── Map control shortcuts ──────────────────────────────────────────────────────

    /**
     * Show the current cursor coordinates in a toast / overlay.
     * Delegates to the active adapter.
     */
    @action showCoordinates(event) {
        return this.adapter?.showCoordinates?.(event);
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
        return this.adapter?.editPolygon?.(layer, options) ?? Promise.resolve({ type: 'cancel' });
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

    // ─── Private ───────────────────────────────────────────────────────────────

    #resetReadyDeferred() {
        this.#mapReadyPromise = new Promise((resolve) => {
            this.#resolveMapReady = resolve;
        });
    }
}
