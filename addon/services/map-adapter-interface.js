/**
 * MapAdapterInterface
 *
 * Abstract base class that defines the standardized contract all map provider
 * adapters must implement. This enables FleetOps to support multiple map
 * providers (Leaflet, Google Maps, Mapbox, etc.) without changing any
 * application-level business logic.
 *
 * To add a new map provider:
 *   1. Create `addon/services/map-adapter/<provider-name>.js`
 *   2. Extend this class and implement every method
 *   3. Register the service in `app/services/map-adapter/<provider-name>.js`
 *   4. Set `mapProvider: '<provider-name>'` in `config/environment.js`
 *
 * @module services/map-adapter-interface
 */
import Service from '@ember/service';

export default class MapAdapterInterface extends Service {
    // ─── Internal State ────────────────────────────────────────────────────────

    /**
     * The raw underlying map instance (e.g. a Leaflet map or google.maps.Map).
     * Consumers should never access this directly; use the adapter API instead.
     * @type {*}
     */
    _map = null;

    /**
     * Internal marker registry: id → native marker object.
     * @type {Map<string, *>}
     */
    _markers = new Map();

    /**
     * Internal overlay registry: id → native overlay object (polygon, polyline, circle).
     * @type {Map<string, *>}
     */
    _overlays = new Map();

    // ─── Lifecycle ─────────────────────────────────────────────────────────────

    /**
     * Initialize the map inside the given DOM element.
     *
     * @param {HTMLElement} element - The container element for the map
     * @param {Object} [options={}] - Provider-specific initialization options
     * @returns {*} The native map instance
     */
    initializeMap(element, options = {}) {
        throw new Error(`${this.constructor.name} must implement initializeMap(element, options)`);
    }

    /**
     * Destroy the map and release all associated resources.
     */
    destroyMap() {
        throw new Error(`${this.constructor.name} must implement destroyMap()`);
    }

    /**
     * Called after the map container is resized. Implementations should
     * trigger the provider's internal resize/invalidate logic.
     */
    invalidateSize() {
        throw new Error(`${this.constructor.name} must implement invalidateSize()`);
    }

    // ─── Viewport ──────────────────────────────────────────────────────────────

    /**
     * Set the map center and zoom level immediately (no animation).
     *
     * @param {number} lat
     * @param {number} lng
     * @param {number} [zoom]
     */
    setCenter(lat, lng, zoom) {
        throw new Error(`${this.constructor.name} must implement setCenter(lat, lng, zoom)`);
    }

    /**
     * Animate the viewport to a new center and zoom level.
     *
     * @param {number} lat
     * @param {number} lng
     * @param {number} [zoom]
     * @param {Object} [options={}]
     */
    flyTo(lat, lng, zoom, options = {}) {
        throw new Error(`${this.constructor.name} must implement flyTo(lat, lng, zoom, options)`);
    }

    /**
     * Fit the viewport to the given bounds.
     *
     * @param {Array} bounds - [[swLat, swLng], [neLat, neLng]]
     * @param {Object} [options={}]
     */
    fitBounds(bounds, options = {}) {
        throw new Error(`${this.constructor.name} must implement fitBounds(bounds, options)`);
    }

    /**
     * Smoothly pan the map to a new center without changing zoom.
     *
     * @param {number} lat
     * @param {number} lng
     * @param {Object} [options={}]
     */
    panTo(lat, lng, options = {}) {
        throw new Error(`${this.constructor.name} must implement panTo(lat, lng, options)`);
    }

    /**
     * Increase the zoom level by one step.
     */
    zoomIn() {
        throw new Error(`${this.constructor.name} must implement zoomIn()`);
    }

    /**
     * Decrease the zoom level by one step.
     */
    zoomOut() {
        throw new Error(`${this.constructor.name} must implement zoomOut()`);
    }

    /**
     * Get the current zoom level.
     *
     * @returns {number}
     */
    getZoom() {
        throw new Error(`${this.constructor.name} must implement getZoom()`);
    }

    /**
     * Get the current map center as { lat, lng }.
     *
     * @returns {{ lat: number, lng: number }}
     */
    getCenter() {
        throw new Error(`${this.constructor.name} must implement getCenter()`);
    }

    /**
     * Get the current viewport bounds as [[swLat, swLng], [neLat, neLng]].
     *
     * @returns {Array}
     */
    getBounds() {
        throw new Error(`${this.constructor.name} must implement getBounds()`);
    }

    // ─── Markers ───────────────────────────────────────────────────────────────

    /**
     * Add a marker to the map and register it by id.
     *
     * @param {string} id - Unique identifier for this marker
     * @param {number} lat
     * @param {number} lng
     * @param {Object} [options={}] - May include: iconUrl, iconSize, title, content (HTML element), zIndexOffset
     * @returns {*} The native marker object
     */
    addMarker(id, lat, lng, options = {}) {
        throw new Error(`${this.constructor.name} must implement addMarker(id, lat, lng, options)`);
    }

    /**
     * Update a registered marker's position, optionally with smooth animation.
     *
     * @param {string} id
     * @param {number} lat
     * @param {number} lng
     * @param {boolean} [animated=true]
     * @param {number} [duration=500] - Animation duration in ms
     */
    updateMarkerPosition(id, lat, lng, animated = true, duration = 500) {
        throw new Error(`${this.constructor.name} must implement updateMarkerPosition(id, lat, lng, animated, duration)`);
    }

    /**
     * Rotate a marker to the given heading in degrees.
     *
     * @param {string} id
     * @param {number} degrees
     */
    setMarkerRotation(id, degrees) {
        throw new Error(`${this.constructor.name} must implement setMarkerRotation(id, degrees)`);
    }

    /**
     * Remove a registered marker from the map.
     *
     * @param {string} id
     */
    removeMarker(id) {
        throw new Error(`${this.constructor.name} must implement removeMarker(id)`);
    }

    /**
     * Retrieve the native marker object by id.
     *
     * @param {string} id
     * @returns {*|null}
     */
    getMarker(id) {
        return this._markers.get(id) ?? null;
    }

    /**
     * Check whether a marker with the given id exists.
     *
     * @param {string} id
     * @returns {boolean}
     */
    hasMarker(id) {
        return this._markers.has(id);
    }

    // ─── Overlays ──────────────────────────────────────────────────────────────

    /**
     * Add a polygon overlay.
     *
     * @param {string} id
     * @param {Array} coordinates - Array of [lat, lng] pairs
     * @param {Object} [options={}]
     * @returns {*} The native polygon object
     */
    addPolygon(id, coordinates, options = {}) {
        throw new Error(`${this.constructor.name} must implement addPolygon(id, coordinates, options)`);
    }

    /**
     * Add a polyline overlay.
     *
     * @param {string} id
     * @param {Array} coordinates - Array of [lat, lng] pairs
     * @param {Object} [options={}]
     * @returns {*} The native polyline object
     */
    addPolyline(id, coordinates, options = {}) {
        throw new Error(`${this.constructor.name} must implement addPolyline(id, coordinates, options)`);
    }

    /**
     * Add a circle overlay.
     *
     * @param {string} id
     * @param {number} lat
     * @param {number} lng
     * @param {number} radiusMeters
     * @param {Object} [options={}]
     * @returns {*} The native circle object
     */
    addCircle(id, lat, lng, radiusMeters, options = {}) {
        throw new Error(`${this.constructor.name} must implement addCircle(id, lat, lng, radiusMeters, options)`);
    }

    /**
     * Remove a registered overlay from the map.
     *
     * @param {string} id
     */
    removeOverlay(id) {
        throw new Error(`${this.constructor.name} must implement removeOverlay(id)`);
    }

    /**
     * Remove all registered overlays from the map.
     */
    clearOverlays() {
        for (const id of this._overlays.keys()) {
            this.removeOverlay(id);
        }
    }

    /**
     * Retrieve the native overlay object by id.
     *
     * @param {string} id
     * @returns {*|null}
     */
    getOverlay(id) {
        return this._overlays.get(id) ?? null;
    }

    // ─── Drawing Tools ─────────────────────────────────────────────────────────

    /**
     * Enable the drawing mode for the given shape type.
     *
     * @param {'polygon'|'circle'|'rectangle'|'polyline'|'marker'|null} type
     */
    enableDrawingMode(type) {
        throw new Error(`${this.constructor.name} must implement enableDrawingMode(type)`);
    }

    /**
     * Disable drawing mode and return to normal interaction.
     */
    disableDrawingMode() {
        throw new Error(`${this.constructor.name} must implement disableDrawingMode()`);
    }

    /**
     * Show the drawing toolbar UI control.
     */
    showDrawControl() {
        throw new Error(`${this.constructor.name} must implement showDrawControl()`);
    }

    /**
     * Hide the drawing toolbar UI control.
     */
    hideDrawControl() {
        throw new Error(`${this.constructor.name} must implement hideDrawControl()`);
    }

    // ─── Popups / Info Windows ─────────────────────────────────────────────────

    /**
     * Open a popup at the given coordinates with HTML content.
     *
     * @param {string} id - Unique identifier for this popup
     * @param {number} lat
     * @param {number} lng
     * @param {string|HTMLElement} htmlContent
     * @returns {*} The native popup/info-window object
     */
    openPopup(id, lat, lng, htmlContent) {
        throw new Error(`${this.constructor.name} must implement openPopup(id, lat, lng, htmlContent)`);
    }

    /**
     * Close and remove a popup by id.
     *
     * @param {string} id
     */
    closePopup(id) {
        throw new Error(`${this.constructor.name} must implement closePopup(id)`);
    }

    // ─── Context Menus ─────────────────────────────────────────────────────────

    /**
     * Register a right-click context menu on the map or a specific marker.
     *
     * @param {'map'|string} target - 'map' or a marker id
     * @param {Array<{label: string, action: Function, separator?: boolean}>} items
     */
    registerContextMenu(target, items) {
        throw new Error(`${this.constructor.name} must implement registerContextMenu(target, items)`);
    }

    /**
     * Remove a previously registered context menu.
     *
     * @param {'map'|string} target
     */
    removeContextMenu(target) {
        throw new Error(`${this.constructor.name} must implement removeContextMenu(target)`);
    }

    // ─── Events ────────────────────────────────────────────────────────────────

    /**
     * Register an event listener on the map.
     * Normalized event names: 'click', 'dblclick', 'rightclick', 'moveend',
     * 'zoomend', 'load', 'draw:created', 'draw:edited', 'draw:deleted'
     *
     * @param {string} event
     * @param {Function} handler
     */
    on(event, handler) {
        throw new Error(`${this.constructor.name} must implement on(event, handler)`);
    }

    /**
     * Remove an event listener from the map.
     *
     * @param {string} event
     * @param {Function} handler
     */
    off(event, handler) {
        throw new Error(`${this.constructor.name} must implement off(event, handler)`);
    }

    /**
     * Register a one-time event listener on the map.
     *
     * @param {string} event
     * @param {Function} handler
     */
    once(event, handler) {
        throw new Error(`${this.constructor.name} must implement once(event, handler)`);
    }

    // ─── Utilities ─────────────────────────────────────────────────────────────

    /**
     * Calculate the distance in metres between two coordinates.
     *
     * @param {number} lat1
     * @param {number} lng1
     * @param {number} lat2
     * @param {number} lng2
     * @returns {number} Distance in metres
     */
    distanceBetween(lat1, lng1, lat2, lng2) {
        throw new Error(`${this.constructor.name} must implement distanceBetween(lat1, lng1, lat2, lng2)`);
    }

    /**
     * Render a GeoJSON object onto the map.
     *
     * @param {string} id - Unique identifier for this GeoJSON layer
     * @param {Object} geojson - A valid GeoJSON FeatureCollection or Feature
     * @param {Object} [options={}]
     * @returns {*} The native GeoJSON layer object
     */
    addGeoJson(id, geojson, options = {}) {
        throw new Error(`${this.constructor.name} must implement addGeoJson(id, geojson, options)`);
    }

    /**
     * Remove a GeoJSON layer by id.
     *
     * @param {string} id
     */
    removeGeoJson(id) {
        throw new Error(`${this.constructor.name} must implement removeGeoJson(id)`);
    }

    /**
     * Set the map's tile/base layer URL (for providers that support custom tiles).
     *
     * @param {string} url
     * @param {Object} [options={}]
     */
    setTileLayer(url, options = {}) {
        // Optional — providers that don't support custom tiles can no-op this.
    }
}
