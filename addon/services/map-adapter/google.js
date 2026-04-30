/* global google */
/**
 * GoogleMapsAdapter
 *
 * Implements the MapAdapterInterface using the Google Maps JavaScript API.
 * Provides 1-to-1 feature parity with the LeafletAdapter, including:
 *
 *   - Live tracking markers with smooth animation (custom rAF interpolation)
 *   - Marker rotation via CSS transforms on AdvancedMarkerElement
 *   - Geofence drawing via DrawingManager (with fallback for deprecation)
 *   - Editable polygons and circles
 *   - Custom right-click context menus
 *   - InfoWindow popups with custom HTML
 *   - GeoJSON rendering via the Data layer
 *   - Custom tile overlays via ImageMapType
 *   - Normalized event system matching the Leaflet adapter's event names
 *
 * Prerequisites:
 *   - `GOOGLE_MAPS_API_KEY` must be set in config/environment.js
 *   - `GOOGLE_MAPS_MAP_ID` must be set for AdvancedMarkerElement support
 *   - The Google Maps JS API is loaded lazily on first initializeMap() call
 *
 * @module services/map-adapter/google
 */
import MapAdapterInterface from '../map-adapter-interface';
import { getOwner } from '@ember/application';
import { isArray } from '@ember/array';
import { debug } from '@ember/debug';
import { waypointIconHtml } from '../../utils/route-colors';
import { Circle, Feature, Polygon } from '@fleetbase/fleetops-data/utils/geojson';

const DEFAULT_GOOGLE_MAP_STYLES = [
    {
        featureType: 'all',
        elementType: 'labels.icon',
        stylers: [{ visibility: 'off' }],
    },
    {
        featureType: 'poi',
        elementType: 'all',
        stylers: [{ visibility: 'off' }],
    },
    {
        featureType: 'transit',
        elementType: 'all',
        stylers: [{ visibility: 'off' }],
    },
    {
        featureType: 'administrative.land_parcel',
        elementType: 'all',
        stylers: [{ visibility: 'off' }],
    },
    {
        featureType: 'road',
        elementType: 'labels.icon',
        stylers: [{ visibility: 'off' }],
    },
    {
        featureType: 'landscape.man_made',
        elementType: 'labels',
        stylers: [{ visibility: 'off' }],
    },
];

function buildWaypointMarkerContent(label, color) {
    const wrapper = document.createElement('div');
    wrapper.className = 'fleetops-map-marker';
    wrapper.style.cssText = 'transform-origin: center center; display: block;';
    wrapper.innerHTML = waypointIconHtml(label, color);
    return wrapper;
}

function buildWaypointMarkerSvg(label, color) {
    const svg = `
        <svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 34 34">
            <circle cx="17" cy="17" r="15" fill="${color}" stroke="white" stroke-width="2" />
            <text x="17" y="21" text-anchor="middle" font-family="Inter, Arial, sans-serif" font-size="14" font-weight="700" fill="white">${label}</text>
        </svg>
    `;

    return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`;
}

export default class GoogleMapsAdapter extends MapAdapterInterface {
    // ─── Internal State ────────────────────────────────────────────────────────

    /** @type {google.maps.Map|null} */
    _map = null;

    /** @type {Map<string, google.maps.marker.AdvancedMarkerElement>} */
    _markers = new Map();

    /** @type {Map<string, google.maps.Polygon|google.maps.Polyline|google.maps.Circle>} */
    _overlays = new Map();

    /** @type {Map<string, google.maps.InfoWindow>} */
    _popups = new Map();

    /** @type {Map<string, google.maps.Data>} */
    _geojsonLayers = new Map();

    /** @type {google.maps.drawing.DrawingManager|null} */
    _drawingManager = null;

    /** @type {HTMLElement|null} DOM element for the custom draw toolbar */
    _drawControlEl = null;

    /** @type {google.maps.ImageMapType|null} */
    _customTileLayer = null;

    /** @type {Map<string, Function[]>} Normalized event name → array of [handler, gmListener] pairs */
    _eventListeners = new Map();

    /** @type {Map<string, HTMLElement>} Context menu DOM elements */
    _contextMenuEls = new Map();

    /** @type {Map<string, Array>} */
    _contextMenus = new Map();

    /** @type {Map<string, Function>} Active animation frame cancel functions */
    _animations = new Map();

    /** @type {boolean} Whether the Google Maps API has been loaded */
    _apiLoaded = false;

    /** @type {*|null} */
    _advancedMarkerElementClass = null;

    /** @type {boolean} */
    _supportsAdvancedMarkers = false;

    /** @type {Object|null} */
    _drawControlConfig = null;

    /** @type {HTMLElement|null} */
    _drawActionEl = null;

    /** @type {*|null} */
    _selectedOverlay = null;

    /** @type {Set<*>} */
    _draftOverlays = new Set();

    /** @type {Set<*>} */
    _pendingDeletedDrafts = new Set();

    /** @type {Function|null} */
    _contextMenuCleanup = null;

    /** @type {google.maps.OverlayView|null} */
    _tooltipOverlayView = null;

    // ─── Lifecycle ─────────────────────────────────────────────────────────────

    async initializeMap(element, options = {}) {
        this.destroyMap();
        await this.#loadGoogleMapsApi(options);
        const googleMaps = window.google;
        const { Map } = await googleMaps.maps.importLibrary('maps');
        const { AdvancedMarkerElement } = await googleMaps.maps.importLibrary('marker');
        this._advancedMarkerElementClass = AdvancedMarkerElement;
        const mergedGoogleOptions = options.googleOptions ?? {};
        const mergedMapStyles = mergedGoogleOptions.styles === undefined ? DEFAULT_GOOGLE_MAP_STYLES : [...DEFAULT_GOOGLE_MAP_STYLES, ...mergedGoogleOptions.styles];
        const mapId = options.mapId ?? mergedGoogleOptions.mapId ?? this.#getConfig()?.googleMaps?.mapId ?? this.#getConfig()?.GOOGLE_MAPS_MAP_ID ?? null;
        this._supportsAdvancedMarkers = Boolean(mapId && mapId !== 'FLEETOPS_MAP');

        this._map = new Map(element, {
            center: { lat: options.lat ?? 1.3521, lng: options.lng ?? 103.8198 },
            zoom: options.zoom ?? 12,
            mapTypeId: options.mapTypeId ?? googleMaps.maps.MapTypeId.ROADMAP,
            disableDefaultUI: options.disableDefaultUI ?? true,
            gestureHandling: options.gestureHandling ?? 'greedy',
            clickableIcons: mergedGoogleOptions.clickableIcons ?? false,
            ...mergedGoogleOptions,
            ...(mapId ? { mapId } : {}),
            styles: mergedMapStyles,
        });

        await this.#initDrawingManager();

        debug('[GoogleMapsAdapter] Map initialized');
        return this._map;
    }

    destroyMap() {
        // Cancel all running animations
        for (const cancel of this._animations.values()) {
            cancel();
        }
        this._animations.clear();

        // Remove all markers
        for (const marker of this._markers.values()) {
            marker.map = null;
        }
        this._markers.clear();

        // Remove all overlays
        for (const overlay of this._overlays.values()) {
            overlay.setMap(null);
        }
        this._overlays.clear();
        this._routingControls.clear();

        // Close all popups
        for (const popup of this._popups.values()) {
            popup.close();
        }
        this._popups.clear();

        // Remove context menus
        for (const el of this._contextMenuEls.values()) {
            el.remove();
        }
        this._contextMenuEls.clear();
        this._contextMenus.clear();
        this._eventListeners.clear();

        // Remove draw control
        this._drawControlEl?.remove();
        this._drawControlEl = null;
        this._drawActionEl?.remove();
        this._drawActionEl = null;
        this._drawingManager?.setMap(null);
        this._drawingManager = null;
        this._selectedOverlay = null;
        this._draftOverlays.clear();
        this._pendingDeletedDrafts.clear();
        this._contextMenuCleanup?.();
        this._contextMenuCleanup = null;
        this._tooltipOverlayView?.setMap?.(null);
        this._tooltipOverlayView = null;
        this._supportsAdvancedMarkers = false;

        this._map = null;
        debug('[GoogleMapsAdapter] Map destroyed');
    }

    invalidateSize() {
        if (!this._map) return;
        google.maps.event.trigger(this._map, 'resize');
    }

    // ─── Viewport ──────────────────────────────────────────────────────────────

    setCenter(lat, lng, zoom) {
        if (!this._map) return;
        this._map.setCenter({ lat, lng });
        if (zoom !== undefined) this._map.setZoom(zoom);
    }

    flyTo(lat, lng, zoom, options = {}) {
        if (!this._map) return;
        // Google Maps has no built-in flyTo; use panTo + optional zoom change
        this._map.panTo({ lat, lng });
        if (zoom !== undefined) {
            // Delay zoom slightly to allow pan animation to start
            setTimeout(() => this._map?.setZoom(zoom), options.delay ?? 200);
        }
    }

    fitBounds(bounds, options = {}) {
        if (!this._map) return;
        const gmBounds = this.#normalizeBounds(bounds);
        if (!gmBounds) return;
        const maxZoom = Number.isFinite(options.maxZoom) ? options.maxZoom : null;
        const padding = options.paddingBottomRight ?? options.padding ?? null;

        if (maxZoom !== null) {
            google.maps.event.addListenerOnce(this._map, 'idle', () => {
                const currentZoom = this._map?.getZoom?.();
                if (Number.isFinite(currentZoom) && currentZoom > maxZoom) {
                    this._map?.setZoom?.(maxZoom);
                }
            });
        }

        if (padding) {
            this._map.fitBounds(gmBounds, { right: padding[0] ?? 0, bottom: padding[1] ?? 0 });
        } else {
            this._map.fitBounds(gmBounds);
        }
    }

    panTo(lat, lng) {
        if (!this._map) return;
        this._map.panTo({ lat, lng });
    }

    zoomIn() {
        if (!this._map) return;
        this._map.setZoom(this._map.getZoom() + 1);
    }

    zoomOut() {
        if (!this._map) return;
        this._map.setZoom(this._map.getZoom() - 1);
    }

    getZoom() {
        return this._map?.getZoom() ?? 0;
    }

    getCenter() {
        const c = this._map?.getCenter();
        return c ? { lat: c.lat(), lng: c.lng() } : { lat: 0, lng: 0 };
    }

    getBounds() {
        const b = this._map?.getBounds();
        if (!b) {
            return [
                [0, 0],
                [0, 0],
            ];
        }

        return [
            [b.getSouthWest().lat(), b.getSouthWest().lng()],
            [b.getNorthEast().lat(), b.getNorthEast().lng()],
        ];
    }

    // ─── Markers ───────────────────────────────────────────────────────────────

    async addMarker(id, lat, lng, options = {}) {
        if (!this._map) return null;

        if (!this._supportsAdvancedMarkers) {
            const classicMarker = this.#createClassicMarker(lat, lng, options);
            if (!classicMarker) return null;

            this._markers.set(id, classicMarker);
            return classicMarker;
        }

        const { AdvancedMarkerElement } = await google.maps.importLibrary('marker');

        // Build the content element — either a custom HTML element or an img icon
        let content = options.content ?? null;
        if (!content && options.iconUrl) {
            const img = document.createElement('img');
            img.src = options.iconUrl;
            const size = options.iconSize ?? [24, 24];
            img.style.width = `${size[0]}px`;
            img.style.height = `${size[1]}px`;
            img.style.display = 'block';
            img.alt = options.alt ?? options.title ?? '';
            // Wrapper div allows rotation transforms without affecting the anchor
            const wrapper = document.createElement('div');
            wrapper.className = 'fleetops-map-marker';
            wrapper.style.cssText = 'transform-origin: center center; display: block;';
            wrapper.appendChild(img);
            content = wrapper;
        }

        if (content && options.title) {
            content.setAttribute?.('title', options.title);
        }

        const marker = new AdvancedMarkerElement({
            map: this._map,
            position: { lat, lng },
            content,
            title: options.title ?? '',
            zIndex: options.zIndexOffset ?? 0,
        });

        // Apply initial rotation
        if (options.rotationAngle && content) {
            content.style.transform = `rotate(${options.rotationAngle}deg)`;
        }

        // Click handler
        if (typeof options.onClick === 'function') {
            marker.addListener('click', options.onClick);
        }

        if (typeof options.onRightClick === 'function') {
            marker.addListener('rightclick', (event) => options.onRightClick(this.#normalizeMapEvent('rightclick', event)));
        }

        if (options.tooltip && content) {
            marker.__tooltipCleanup = this.#attachMarkerTooltip(content, options.tooltip, options.tooltipOptions ?? {});
        }

        this._markers.set(id, marker);
        return marker;
    }

    updateMarkerPosition(id, lat, lng, animated = true, duration = 500) {
        const marker = this._markers.get(id);
        if (!marker) return;

        const nextPosition = this.#normalizeMarkerPosition(lat, lng);
        if (!nextPosition) return;

        // Cancel any running animation for this marker
        const cancelPrev = this._animations.get(id);
        if (cancelPrev) {
            cancelPrev();
            this._animations.delete(id);
        }

        if (!animated || duration <= 0) {
            this.#setMarkerPosition(marker, nextPosition);
            return;
        }

        // Smooth animation via requestAnimationFrame interpolation
        const startPos = this.#extractMarkerPosition(marker);
        if (!startPos) {
            this.#setMarkerPosition(marker, nextPosition);
            return;
        }

        const startLat = startPos.lat;
        const startLng = startPos.lng;
        const startTime = performance.now();
        let rafId = null;
        let cancelled = false;

        const animate = (currentTime) => {
            if (cancelled) return;
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            // Ease-out cubic for natural deceleration
            const eased = 1 - Math.pow(1 - progress, 3);
            const currentLat = startLat + (lat - startLat) * eased;
            const currentLng = startLng + (lng - startLng) * eased;
            this.#setMarkerPosition(marker, { lat: currentLat, lng: currentLng });
            if (progress < 1) {
                rafId = requestAnimationFrame(animate);
            } else {
                this._animations.delete(id);
            }
        };

        rafId = requestAnimationFrame(animate);
        this._animations.set(id, () => {
            cancelled = true;
            if (rafId) cancelAnimationFrame(rafId);
        });
    }

    setMarkerRotation(id, degrees) {
        const marker = this._markers.get(id);
        if (!marker?.content) return;
        // Apply rotation to the content element
        marker.content.style.transform = `rotate(${degrees}deg)`;
        marker.content.style.transformOrigin = 'center center';
    }

    removeMarker(id) {
        const marker = this._markers.get(id);
        if (marker) {
            // Cancel animation
            const cancel = this._animations.get(id);
            if (cancel) {
                cancel();
                this._animations.delete(id);
            }
            marker.__tooltipCleanup?.();
            if (typeof marker.setMap === 'function') {
                marker.setMap(null);
            } else {
                marker.map = null;
            }
            this._markers.delete(id);
        }
    }

    // ─── Overlays ──────────────────────────────────────────────────────────────

    addPolygon(id, coordinates, options = {}) {
        if (!this._map) return null;
        const paths = this.#normalizePolygonPaths(coordinates);
        if (!paths) return null;

        const polygon = new google.maps.Polygon({
            paths,
            map: this._map,
            strokeColor: options.color ?? '#3388ff',
            strokeWeight: options.weight ?? 3,
            strokeOpacity: 1,
            fillColor: options.fillColor ?? options.color ?? '#3388ff',
            fillOpacity: options.fillOpacity ?? 0.2,
            editable: options.editable ?? false,
            clickable: options.clickable ?? true,
            zIndex: options.zIndex ?? 1,
        });

        if (typeof options.onRightClick === 'function') {
            polygon.addListener('rightclick', (event) => {
                this._selectedOverlay = polygon;
                options.onRightClick(this.#normalizeMapEvent('rightclick', event));
            });
        }

        polygon.addListener('click', () => {
            this._selectedOverlay = polygon;
        });

        if (options.tooltip) {
            polygon.__labelText = options.tooltip;
            polygon.__labelPaths = paths;
            polygon.__labelMarker = this.#createOverlayLabel(paths, options.tooltip);
        }

        this._overlays.set(id, polygon);
        return polygon;
    }

    addPolyline(id, coordinates, options = {}) {
        if (!this._map) return null;
        const path = this.#normalizeLinePath(coordinates);
        if (!path) return null;

        const polyline = new google.maps.Polyline({
            path,
            map: this._map,
            strokeColor: options.color ?? '#3388ff',
            strokeWeight: options.weight ?? 3,
            strokeOpacity: options.opacity ?? 1,
        });

        this._overlays.set(id, polyline);
        return polyline;
    }

    addCircle(id, lat, lng, radiusMeters, options = {}) {
        if (!this._map) return null;
        const circle = new google.maps.Circle({
            center: { lat, lng },
            radius: radiusMeters,
            map: this._map,
            strokeColor: options.color ?? '#3388ff',
            strokeWeight: options.weight ?? 3,
            strokeOpacity: 1,
            fillColor: options.fillColor ?? options.color ?? '#3388ff',
            fillOpacity: options.fillOpacity ?? 0.2,
            editable: options.editable ?? false,
            clickable: options.clickable ?? true,
        });

        this._overlays.set(id, circle);
        return circle;
    }

    removeOverlay(id) {
        const overlay = this._overlays.get(id);
        if (overlay) {
            overlay.setMap(null);
            if (overlay.__labelMarker) {
                overlay.__labelMarker.map = null;
            }
            this._overlays.delete(id);
        }
    }

    async addRoutingControl(route, options = {}) {
        if (!this._map || !route?.waypoints?.length) return null;

        const handleId = options.id ?? `route:${Date.now().toString(36)}:${Math.random().toString(36).slice(2, 8)}`;
        const routeStyles = options.polylineOptions?.styles ?? null;
        const polylineIds = [];

        if (route.coordinates?.length) {
            const polylineLayers = routeStyles?.length
                ? routeStyles.map((style, index) => ({
                      id: `${handleId}:polyline:${index}`,
                      options: {
                          color: style.color,
                          weight: style.weight,
                          opacity: style.opacity,
                      },
                  }))
                : [
                      {
                          id: `${handleId}:polyline:0`,
                          options: {
                              color: options.polylineOptions?.color ?? options.color ?? '#2563eb',
                              weight: options.polylineOptions?.weight ?? 4,
                              opacity: options.polylineOptions?.opacity ?? 0.85,
                          },
                      },
                  ];

            polylineLayers.forEach(({ id, options: polylineOptions }) => {
                const polyline = this.addPolyline(id, route.coordinates, polylineOptions);
                if (polyline) {
                    polylineIds.push(id);
                }
            });
        }
        const markerIds = [];

        if (!options.suppressMarkers) {
            for (const [index, waypoint] of route.waypoints.entries()) {
                const markerId = `${handleId}:marker:${index}`;
                const markerOptions = this.#buildRouteMarkerOptions(waypoint, index, route, options);
                if (markerOptions === null) continue;

                await this.addMarker(markerId, waypoint[0], waypoint[1], markerOptions);
                markerIds.push(markerId);
            }
        }

        const handle = {
            id: handleId,
            engine: route.engine,
            route,
            markerIds,
            polylineIds,
            tag: options.tag ?? null,
            raw: route.raw,
            bounds: route.bounds,
        };

        this._routingControls.set(handleId, handle);
        return handle;
    }

    removeRoutingControl(handle) {
        const resolvedHandle = typeof handle === 'string' ? this._routingControls.get(handle) : handle;
        if (!resolvedHandle) return false;

        resolvedHandle.markerIds?.forEach((id) => this.removeMarker(id));
        resolvedHandle.polylineIds?.forEach((id) => this.removeOverlay(id));
        this._routingControls.delete(resolvedHandle.id);
        return true;
    }

    positionWaypoints(waypointsOrBounds, options = {}) {
        if (!this._map) return null;

        const isBounds = options.isBounds === true;
        const bounds = isBounds ? waypointsOrBounds : null;
        const waypoints = isBounds ? null : waypointsOrBounds;
        const paddingBottomRight = options.paddingBottomRight ?? [300, 0];
        const singlePointZoom = options.singlePointZoom ?? 18;
        const maxZoom = options.maxZoom ?? (isArray(waypoints) && waypoints.length === 2 ? 15 : 14);
        const panBy = options.panBy ?? [0, 0];

        if (isArray(waypoints) && waypoints.length === 1) {
            this.flyTo(waypoints[0][0], waypoints[0][1], singlePointZoom, { animate: true });
            google.maps.event.addListenerOnce(this._map, 'idle', () => this.panBy(panBy[0], panBy[1]));
            return true;
        }

        if (bounds || (isArray(waypoints) && waypoints.length > 1)) {
            this.fitBounds(bounds ?? waypoints, {
                paddingBottomRight,
                maxZoom,
                animate: true,
            });
            google.maps.event.addListenerOnce(this._map, 'idle', () => this.panBy(panBy[0], panBy[1]));
            return true;
        }

        return null;
    }

    #getConfig() {
        try {
            const owner = getOwner(this);
            const config = owner?.resolveRegistration('config:environment');
            return config?.['@fleetbase/fleetops-engine'] ?? config ?? null;
        } catch {
            return null;
        }
    }

    removeLayer(layer) {
        if (!layer) return;

        if (typeof layer.setMap === 'function') {
            layer.setMap(null);
        } else if ('map' in layer) {
            layer.map = null;
        }

        this._draftOverlays.delete(layer);
        this._pendingDeletedDrafts.delete(layer);
        if (this._selectedOverlay === layer) {
            this._selectedOverlay = null;
        }
    }

    // ─── Layer Visibility ──────────────────────────────────────────────────────

    showLayer(layer) {
        if (!layer) return;
        if (typeof layer.setMap === 'function') {
            layer.setMap(this._map);
        } else if (layer.map === null) {
            layer.map = this._map;
        }
        this.#syncOverlayPresentation(layer);
        if (layer.__labelMarker) {
            layer.__labelMarker.map = this._map;
        }
        layer.__hidden = false;
    }

    hideLayer(layer) {
        if (!layer) return;
        if (typeof layer.setMap === 'function') {
            layer.setMap(null);
        } else {
            layer.map = null;
        }
        if (layer.__labelMarker) {
            layer.__labelMarker.map = null;
        }
        layer.__hidden = true;
    }

    isLayerHidden(layer) {
        if (!layer) return true;
        return layer.__hidden === true;
    }

    isLayerVisible(layer) {
        return !this.isLayerHidden(layer);
    }

    // ─── Drawing Tools ─────────────────────────────────────────────────────────

    enableDrawingMode(type, options = {}) {
        if (!this._drawingManager) {
            debug('[GoogleMapsAdapter] DrawingManager not initialized');
            return;
        }

        const modeMap = {
            polygon: google.maps.drawing.OverlayType.POLYGON,
            circle: google.maps.drawing.OverlayType.CIRCLE,
            rectangle: google.maps.drawing.OverlayType.RECTANGLE,
            polyline: google.maps.drawing.OverlayType.POLYLINE,
            marker: google.maps.drawing.OverlayType.MARKER,
        };

        this._drawingManager.setDrawingMode(modeMap[type] ?? null);
        this._drawingManager.setMap(this._map);
        this._drawingOnCreate = typeof options.onCreate === 'function' ? options.onCreate : null;
    }

    disableDrawingMode() {
        this._drawingManager?.setDrawingMode(null);
        this._drawingOnCreate = null;
        // Fire normalized event for listeners
        this.#fireNormalizedEvent('draw:drawstop', {});
    }

    showDrawControl(config = {}) {
        this._drawControlConfig = config;
        this.#ensureDrawToolbar();

        if (this._drawControlEl) {
            this._drawControlEl.style.display = '';
        }

        this.#applyDrawToolbarConfig(config);

        if (config?.defaultMode) {
            this.enableDrawingMode(config.defaultMode);
        }
    }

    hideDrawControl() {
        if (this._drawControlEl) {
            this._drawControlEl.style.display = 'none';
        }
        this._drawActionEl?.remove();
        this._drawActionEl = null;
        this.#restorePendingDeletedDrafts();
        this.disableDrawingMode();
    }

    // ─── Popups / Info Windows ─────────────────────────────────────────────────

    openPopup(id, lat, lng, htmlContent) {
        if (!this._map) return null;
        const infoWindow = new google.maps.InfoWindow({
            content: typeof htmlContent === 'string' ? htmlContent : htmlContent,
            position: { lat, lng },
        });
        infoWindow.open({ map: this._map });
        this._popups.set(id, infoWindow);
        return infoWindow;
    }

    closePopup(id) {
        const popup = this._popups.get(id);
        if (popup) {
            popup.close();
            this._popups.delete(id);
        }
    }

    // ─── Context Menus ─────────────────────────────────────────────────────────

    registerContextMenu(target, items) {
        this._contextMenus.set(target, items);
    }

    removeContextMenu(target) {
        this._contextMenus.delete(target);
        const el = this._contextMenuEls.get(target);
        if (el) {
            el.remove();
            this._contextMenuEls.delete(target);
        }
    }

    getContextMenuItems(target) {
        return this._contextMenus.get(target) ?? [];
    }

    showContextMenu(event, items = []) {
        const latlng = event?.latlng;
        if (!latlng || !items.length) return;
        this.closeContextMenu();
        this.#renderContextMenu(event?.originalEvent, latlng, items);
    }

    closeContextMenu() {
        this._contextMenuCleanup?.();
        this._contextMenuCleanup = null;
    }

    // ─── Events ────────────────────────────────────────────────────────────────

    on(event, handler) {
        if (!this._map) return;
        const gmEvent = this.#normalizeEvent(event);

        if (gmEvent.startsWith('draw:')) {
            if (!this._eventListeners.has(event)) {
                this._eventListeners.set(event, []);
            }

            this._eventListeners.get(event).push({ handler });
        } else {
            const listener = this._map.addListener(gmEvent, (e) => {
                handler(this.#normalizeMapEvent(event, e));
            });
            if (!this._eventListeners.has(event)) {
                this._eventListeners.set(event, []);
            }
            this._eventListeners.get(event).push({ handler, listener });
        }
    }

    off(event, handler) {
        const listeners = this._eventListeners.get(event) ?? [];
        const entry = listeners.find((e) => e.handler === handler);
        if (entry) {
            if (entry.listener) {
                google.maps.event.removeListener(entry.listener);
            }
            this._eventListeners.set(
                event,
                listeners.filter((e) => e !== entry)
            );
        }
    }

    once(event, handler) {
        if (!this._map) return;
        if (event.startsWith('draw:')) {
            const wrappedHandler = (payload) => {
                this.off(event, wrappedHandler);
                handler(payload);
            };

            this.on(event, wrappedHandler);
            return wrappedHandler;
        }

        const gmEvent = this.#normalizeEvent(event);
        const listener = google.maps.event.addListenerOnce(this._map, gmEvent, (e) => {
            handler(this.#normalizeMapEvent(event, e));
        });
        return listener;
    }

    // ─── Utilities ─────────────────────────────────────────────────────────────

    distanceBetween(lat1, lng1, lat2, lng2) {
        return google.maps.geometry.spherical.computeDistanceBetween(new google.maps.LatLng(lat1, lng1), new google.maps.LatLng(lat2, lng2));
    }

    addGeoJson(id, geojson, options = {}) {
        if (!this._map) return null;
        const dataLayer = new google.maps.Data({ map: this._map });
        dataLayer.addGeoJson(geojson);
        if (options.style) {
            dataLayer.setStyle(options.style);
        }
        this._geojsonLayers.set(id, dataLayer);
        return dataLayer;
    }

    removeGeoJson(id) {
        const layer = this._geojsonLayers.get(id);
        if (layer) {
            layer.setMap(null);
            this._geojsonLayers.delete(id);
        }
    }

    setTileLayer(url, options = {}) {
        if (!this._map) return;
        if (this._customTileLayer) {
            this._map.overlayMapTypes.clear();
        }
        // Create a custom ImageMapType for the tile URL
        this._customTileLayer = new google.maps.ImageMapType({
            getTileUrl: (coord, zoom) => {
                return url
                    .replace('{z}', zoom)
                    .replace('{x}', coord.x)
                    .replace('{y}', coord.y)
                    .replace('{s}', ['a', 'b', 'c'][Math.floor(Math.random() * 3)]);
            },
            tileSize: new google.maps.Size(256, 256),
            name: options.name ?? 'Custom Tiles',
            opacity: options.opacity ?? 1,
        });
        this._map.overlayMapTypes.insertAt(0, this._customTileLayer);
    }

    registerMarker(id, markerObject) {
        return super.registerMarker(id, markerObject);
    }

    registerPolygon(id, polygonObject) {
        return super.registerPolygon(id, polygonObject);
    }

    showCoordinates(event) {
        const lat = event?.latlng?.lat ?? event?.latlng?.lat?.();
        const lng = event?.latlng?.lng ?? event?.latlng?.lng?.();

        return { lat, lng };
    }

    centerMap(event) {
        const lat = event?.latlng?.lat ?? event?.latlng?.lat?.();
        const lng = event?.latlng?.lng ?? event?.latlng?.lng?.();

        if (Number.isFinite(lat) && Number.isFinite(lng)) {
            this._map?.panTo({ lat, lng });
        }
    }

    toggleDrawControl() {
        this.#ensureDrawToolbar();
        if (!this._drawControlEl) return;

        const isHidden = this._drawControlEl.style.display === 'none' || this._drawControlEl.style.display === '';
        if (!isHidden) {
            this.hideDrawControl();
            return;
        }

        this.showDrawControl(
            this._drawControlConfig ?? {
                tools: ['polygon', 'circle', 'rectangle'],
                allowEdit: true,
                allowDelete: true,
                defaultMode: null,
            }
        );
    }

    editPolygon(layer, { focusBounds = null } = {}) {
        if (!layer || typeof layer.setEditable !== 'function') {
            return Promise.resolve({ type: 'unsupported' });
        }

        const originalState = this.#captureOverlayState(layer);
        if (!originalState) {
            return Promise.resolve({ type: 'unsupported' });
        }

        if (focusBounds) {
            try {
                this.fitBounds(focusBounds, { paddingBottomRight: [0, 0], maxZoom: 16, animate: true });
            } catch {
                // noop
            }
        }

        layer.setEditable(true);
        const cleanupActionBar = this.#showEditActionBar({
            onSave: () => {
                const geoJson = this.#geoJsonFromOverlay(layer);
                finalize({
                    type: 'edited',
                    layer,
                    geoJson,
                    toGeoJSON: () => geoJson,
                });
            },
            onCancel: () => {
                this.#restoreOverlayState(layer, originalState);
                finalize({ type: 'cancel' });
            },
        });

        let settled = false;
        const finalize = (result) => {
            if (settled) return;
            settled = true;
            layer.setEditable(false);
            cleanupActionBar?.();
            resolve(result);
        };

        let resolve;
        return new Promise((promiseResolve) => {
            resolve = promiseResolve;
        });
    }

    panBy(x, y = 0) {
        this._map?.panBy?.(x, y);
    }

    #buildRouteMarkerOptions(waypoint, index, route, options) {
        if (typeof options.createMarker === 'function') {
            const customOptions = options.createMarker(waypoint, index, route);
            if (customOptions === null || customOptions === false) {
                return null;
            }

            if (customOptions && typeof customOptions === 'object') {
                if (customOptions.waypointLabel) {
                    return {
                        ...customOptions,
                        content: buildWaypointMarkerContent(customOptions.waypointLabel, customOptions.waypointColor ?? '#2563eb'),
                        iconSize: customOptions.iconSize ?? [32, 32],
                        iconAnchor: customOptions.iconAnchor ?? [16, 16],
                    };
                }

                return customOptions;
            }
        }

        return {
            iconUrl: '/assets/images/marker-icon.png',
            iconSize: [25, 41],
            iconAnchor: [12, 41],
            draggable: false,
            ...options.markerOptions,
        };
    }

    // ─── Private ───────────────────────────────────────────────────────────────

    /**
     * Load the Google Maps JavaScript API dynamically.
     * Uses the configured API key from environment config.
     *
     * @param {Object} options
     * @returns {Promise<void>}
     */
    async #loadGoogleMapsApi(options = {}) {
        if (this._apiLoaded || (typeof google !== 'undefined' && google.maps)) {
            this._apiLoaded = true;
            return;
        }

        const apiKey = options.apiKey ?? '';

        if (!apiKey) {
            debug('[GoogleMapsAdapter] Warning: No Google Maps API key configured.');
        }

        return new Promise((resolve, reject) => {
            if (typeof google !== 'undefined' && google.maps) {
                this._apiLoaded = true;
                resolve();
                return;
            }

            const callbackName = `__fleetops_gmaps_cb_${Date.now()}`;
            window[callbackName] = () => {
                this._apiLoaded = true;
                delete window[callbackName];
                resolve();
            };

            const script = document.createElement('script');
            script.src = `https://maps.googleapis.com/maps/api/js?key=${apiKey}&libraries=drawing,geometry,marker,routes&callback=${callbackName}&loading=async`;
            script.async = true;
            script.onerror = () => reject(new Error('[GoogleMapsAdapter] Failed to load Google Maps API'));
            document.head.appendChild(script);
        });
    }

    /**
     * Initialize the DrawingManager (hidden by default).
     */
    async #initDrawingManager() {
        try {
            const googleMaps = window.google;
            const { DrawingManager } = await googleMaps.maps.importLibrary('drawing');
            this._drawingManager = new DrawingManager({
                drawingControl: false,
                drawingMode: null,
                polygonOptions: {
                    strokeColor: '#3388ff',
                    fillColor: '#3388ff',
                    fillOpacity: 0.2,
                    strokeWeight: 3,
                    editable: true,
                    clickable: true,
                },
                circleOptions: {
                    strokeColor: '#3388ff',
                    fillColor: '#3388ff',
                    fillOpacity: 0.2,
                    strokeWeight: 3,
                    editable: true,
                    clickable: true,
                },
                rectangleOptions: {
                    strokeColor: '#3388ff',
                    fillColor: '#3388ff',
                    fillOpacity: 0.2,
                    strokeWeight: 3,
                    editable: true,
                    clickable: true,
                },
            });

            // Bridge DrawingManager events to normalized 'draw:created' event
            this._drawingManager.addListener('overlaycomplete', (e) => {
                const typeMap = {
                    [googleMaps.maps.drawing.OverlayType.POLYGON]: 'polygon',
                    [googleMaps.maps.drawing.OverlayType.CIRCLE]: 'circle',
                    [googleMaps.maps.drawing.OverlayType.RECTANGLE]: 'rectangle',
                    [googleMaps.maps.drawing.OverlayType.POLYLINE]: 'polyline',
                    [googleMaps.maps.drawing.OverlayType.MARKER]: 'marker',
                };

                const geoJson = this.#overlayToGeoJson(e.overlay, e.type);
                this._selectedOverlay = e.overlay;
                this._draftOverlays.add(e.overlay);
                e.overlay.__overlayType = typeMap[e.type] ?? e.type;
                this.#bindOverlaySelection(e.overlay);

                const normalizedEvent = {
                    layerType: typeMap[e.type] ?? e.type,
                    layer: e.overlay,
                    geoJson,
                    toGeoJSON: () => geoJson,
                };

                this._drawingOnCreate?.(normalizedEvent);
                this.#fireNormalizedEvent('draw:created', normalizedEvent);
                this._drawingManager.setDrawingMode(null);
            });
        } catch (e) {
            debug('[GoogleMapsAdapter] DrawingManager not available: ' + e.message);
        }
    }

    #ensureDrawToolbar() {
        if (this._drawControlEl || !this._map) return;

        const topOffset = this.#getToolbarTopOffset();
        const container = document.createElement('div');
        container.className = 'fleetops-google-draw';
        container.style.cssText = `
            position:absolute;
            top:${topOffset}px;
            right:16px;
            z-index:5;
            display:none;
            pointer-events:none;
        `;

        const toolSection = document.createElement('div');
        toolSection.className = 'fleetops-google-draw-section';
        const toolGroup = document.createElement('div');
        toolGroup.className = 'fleetops-google-draw-toolbar fleetops-google-draw-toolbar-top fleetops-google-draw-bar';

        const actionSection = document.createElement('div');
        actionSection.className = 'fleetops-google-draw-section';
        const actionGroup = document.createElement('div');
        actionGroup.className = 'fleetops-google-draw-toolbar fleetops-google-draw-bar';

        toolSection.appendChild(toolGroup);
        actionSection.appendChild(actionGroup);
        container.appendChild(toolSection);
        container.appendChild(actionSection);
        this._drawControlEl = container;
        this._drawControlEl.__toolGroup = toolGroup;
        this._drawControlEl.__actionGroup = actionGroup;
        this._drawControlEl.__toolSection = toolSection;
        this._drawControlEl.__actionSection = actionSection;
        this._map.getDiv()?.appendChild(container);
    }

    #applyDrawToolbarConfig(config = {}) {
        if (!this._drawControlEl) return;

        this._drawControlEl.style.top = `${this.#getToolbarTopOffset()}px`;
        const toolGroup = this._drawControlEl.__toolGroup;
        const actionGroup = this._drawControlEl.__actionGroup;
        const actionSection = this._drawControlEl.__actionSection;
        toolGroup.innerHTML = '';
        actionGroup.innerHTML = '';

        const tools = config.tools ?? ['polygon', 'circle', 'rectangle'];
        const toolButtons = [
            ['polygon', '▱'],
            ['rectangle', '▭'],
            ['circle', '◉'],
        ];

        toolButtons
            .filter(([tool]) => tools.includes(tool))
            .forEach(([tool, icon]) => {
                toolGroup.appendChild(
                    this.#createToolbarButton(icon, tool, `fleetops-google-draw-draw-${tool}`, () => {
                        this.enableDrawingMode(tool);
                    })
                );
            });

        if (config.allowEdit) {
            actionGroup.appendChild(
                this.#createToolbarButton('✎', 'edit', 'fleetops-google-draw-edit-edit', () => {
                    this.#editSelectedOverlay();
                })
            );
        }

        if (config.allowDelete) {
            actionGroup.appendChild(
                this.#createToolbarButton('🗑', 'delete', 'fleetops-google-draw-edit-remove', () => {
                    this.#deleteSelectedOverlay();
                })
            );
        }

        actionSection.style.display = config.allowEdit || config.allowDelete ? '' : 'none';
    }

    #createToolbarButton(icon, label, className, onClick) {
        const button = document.createElement('a');
        button.href = '#';
        button.className = className;
        button.setAttribute('aria-label', label);
        button.title = label;
        const srOnly = document.createElement('span');
        srOnly.className = 'sr-only';
        srOnly.textContent = label;
        button.appendChild(srOnly);
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            onClick?.();
        });
        return button;
    }

    #showEditActionBar({ onSave, onCancel, onClearAll = null }) {
        this._drawActionEl?.remove();

        const topOffset = this.#getToolbarTopOffset() + 90;
        const actionBar = document.createElement('ul');
        actionBar.className = 'fleetops-google-draw-actions fleetops-google-draw-actions-top';
        actionBar.style.cssText = `
            position:absolute;
            top:${topOffset}px;
            right:78px;
            z-index:6;
            display:flex;
        `;

        const save = this.#createActionButton('Save', onSave);
        const cancel = this.#createActionButton('Cancel', onCancel);
        const clearAll = typeof onClearAll === 'function' ? this.#createActionButton('Clear All', onClearAll) : null;
        actionBar.appendChild(save);
        actionBar.appendChild(cancel);
        if (clearAll) {
            actionBar.appendChild(clearAll);
        }

        this._drawActionEl = actionBar;
        this._map?.getDiv()?.appendChild(actionBar);

        return () => {
            actionBar.remove();
            if (this._drawActionEl === actionBar) {
                this._drawActionEl = null;
            }
        };
    }

    #createActionButton(label, onClick) {
        const item = document.createElement('li');
        const button = document.createElement('a');
        button.href = '#';
        button.textContent = label;
        button.className = 'fleetops-google-draw-action-link';
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            onClick?.();
        });
        item.appendChild(button);
        return item;
    }

    #editSelectedOverlay() {
        const layer = this._selectedOverlay;
        if (!layer || !this._draftOverlays.has(layer) || typeof layer.setEditable !== 'function') return;

        const originalState = this.#captureOverlayState(layer);
        if (!originalState) return;

        layer.setEditable(true);

        const cleanupActionBar = this.#showEditActionBar({
            onSave: () => {
                const geoJson = this.#geoJsonFromOverlay(layer);
                layer.setEditable(false);
                cleanupActionBar?.();
                this.#fireNormalizedEvent('draw:edited', {
                    type: 'draw:edited',
                    layer,
                    layerType: layer.__overlayType ?? 'polygon',
                    geoJson,
                    toGeoJSON: () => geoJson,
                });
            },
            onCancel: () => {
                this.#restoreOverlayState(layer, originalState);
                layer.setEditable(false);
                cleanupActionBar?.();
            },
        });
    }

    #deleteSelectedOverlay() {
        const layer = this._selectedOverlay;
        if (!layer || !this._draftOverlays.has(layer)) return;

        this._pendingDeletedDrafts.add(layer);
        this.hideLayer(layer);

        const cleanupActionBar = this.#showEditActionBar({
            onSave: () => {
                const deletedLayers = Array.from(this._pendingDeletedDrafts);

                deletedLayers.forEach((draftLayer) => {
                    const geoJson = this.#geoJsonFromOverlay(draftLayer);
                    this._draftOverlays.delete(draftLayer);
                    this._pendingDeletedDrafts.delete(draftLayer);
                    this.#fireNormalizedEvent('draw:deleted', {
                        type: 'draw:deleted',
                        layer: draftLayer,
                        layerType: draftLayer.__overlayType ?? 'polygon',
                        geoJson,
                        toGeoJSON: () => geoJson,
                    });
                });

                this._selectedOverlay = null;
                cleanupActionBar?.();
            },
            onCancel: () => {
                this.#restorePendingDeletedDrafts();
                cleanupActionBar?.();
            },
            onClearAll: () => {
                this._draftOverlays.forEach((draftLayer) => {
                    this._pendingDeletedDrafts.add(draftLayer);
                    this.hideLayer(draftLayer);
                });
            },
        });
    }

    #bindOverlaySelection(layer) {
        if (!layer || typeof layer.addListener !== 'function') return;
        layer.addListener('click', () => {
            this._selectedOverlay = layer;
        });
        layer.addListener('rightclick', () => {
            this._selectedOverlay = layer;
        });
    }

    #restorePendingDeletedDrafts() {
        this._pendingDeletedDrafts.forEach((layer) => {
            this.showLayer(layer);
        });
        this._pendingDeletedDrafts.clear();
    }

    #attachMarkerTooltip(contentEl, tooltipContent, options = {}) {
        if (!contentEl || !tooltipContent) return null;

        let tooltipEl = null;

        const ensureTooltip = () => {
            if (tooltipEl) return tooltipEl;
            tooltipEl = document.createElement('div');
            tooltipEl.className = 'fleetops-google-hover-tooltip';

            if (options.html) {
                tooltipEl.innerHTML = tooltipContent;
            } else {
                tooltipEl.textContent = tooltipContent;
            }

            document.body.appendChild(tooltipEl);
            return tooltipEl;
        };

        const updatePosition = (event) => {
            const el = ensureTooltip();
            el.style.left = `${(event?.clientX ?? 0) + 12}px`;
            el.style.top = `${(event?.clientY ?? 0) - 12}px`;
        };

        const show = (event) => {
            const el = ensureTooltip();
            el.style.display = 'block';
            updatePosition(event);
        };

        const hide = () => {
            if (tooltipEl) {
                tooltipEl.style.display = 'none';
            }
        };

        contentEl.addEventListener('mouseenter', show);
        contentEl.addEventListener('mousemove', updatePosition);
        contentEl.addEventListener('mouseleave', hide);

        return () => {
            contentEl.removeEventListener('mouseenter', show);
            contentEl.removeEventListener('mousemove', updatePosition);
            contentEl.removeEventListener('mouseleave', hide);
            tooltipEl?.remove();
            tooltipEl = null;
        };
    }

    #createOverlayLabel(paths, text) {
        if (!this._map || !text) return null;

        const centroid = this.#getPolygonLabelPosition(paths);
        if (!centroid) return null;

        if (!this._supportsAdvancedMarkers || !this._advancedMarkerElementClass) {
            return new google.maps.Marker({
                map: null,
                position: centroid,
                label: {
                    text,
                    color: '#ffffff',
                    fontSize: '12px',
                    fontWeight: '700',
                },
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    scale: 0,
                },
                title: text,
            });
        }

        const content = document.createElement('div');
        content.className = 'fleetops-google-polygon-label';
        content.textContent = text;

        return new this._advancedMarkerElementClass({
            map: null,
            position: centroid,
            content,
            title: text,
        });
    }

    #syncOverlayPresentation(layer) {
        if (!layer) return;

        if (typeof layer.setOptions === 'function') {
            layer.setOptions({
                strokeOpacity: 1,
                fillOpacity: layer.get?.('fillOpacity') ?? 0.2,
                visible: true,
            });
        }

        if (layer.__labelText && !layer.__labelMarker) {
            const currentPaths = this.#extractPolygonPaths(layer) ?? layer.__labelPaths;
            layer.__labelPaths = currentPaths;
            layer.__labelMarker = this.#createOverlayLabel(currentPaths, layer.__labelText);
        }

        if (layer.__labelMarker) {
            const labelPaths = this.#extractPolygonPaths(layer) ?? layer.__labelPaths;
            if (labelPaths) {
                layer.__labelPaths = labelPaths;
                const centroid = this.#getPolygonLabelPosition(labelPaths);
                if (centroid) {
                    layer.__labelMarker.position = centroid;
                }
            }

            if (layer.__labelMarker.content) {
                layer.__labelMarker.content.textContent = layer.__labelText ?? layer.__labelMarker.title ?? '';
            }

            layer.__labelMarker.title = layer.__labelText ?? layer.__labelMarker.title ?? '';
        }
    }

    #extractPolygonPaths(layer) {
        if (!layer || typeof layer.getPath !== 'function') return null;

        const path = layer.getPath()?.getArray?.() ?? [];
        const points = path
            .map((point) => ({
                lat: typeof point.lat === 'function' ? point.lat() : point.lat,
                lng: typeof point.lng === 'function' ? point.lng() : point.lng,
            }))
            .filter((point) => Number.isFinite(point.lat) && Number.isFinite(point.lng));

        return points.length >= 3 ? points : null;
    }

    #getPolygonLabelPosition(paths) {
        const firstRing = isArray(paths?.[0]) ? paths[0] : paths;
        if (!isArray(firstRing) || firstRing.length === 0) return null;

        const totals = firstRing.reduce(
            (acc, point) => {
                acc.lat += point.lat;
                acc.lng += point.lng;
                acc.count += 1;
                return acc;
            },
            { lat: 0, lng: 0, count: 0 }
        );

        if (totals.count === 0) return null;
        return {
            lat: totals.lat / totals.count,
            lng: totals.lng / totals.count,
        };
    }

    #normalizeBounds(bounds) {
        if (!bounds) return null;

        if (bounds instanceof google.maps.LatLngBounds) {
            return bounds;
        }

        if (
            isArray(bounds) &&
            bounds.length === 2 &&
            isArray(bounds[0]) &&
            typeof bounds[0][0] === 'number' &&
            typeof bounds[0][1] === 'number' &&
            typeof bounds[1]?.[0] === 'number' &&
            typeof bounds[1]?.[1] === 'number'
        ) {
            const [[swLat, swLng], [neLat, neLng]] = bounds;
            return new google.maps.LatLngBounds(new google.maps.LatLng(swLat, swLng), new google.maps.LatLng(neLat, neLng));
        }

        const flatPoints = [];
        this.#collectBoundPoints(bounds, flatPoints);
        if (flatPoints.length === 0) return null;

        const normalizedBounds = new google.maps.LatLngBounds();
        flatPoints.forEach((point) => normalizedBounds.extend(point));
        return normalizedBounds;
    }

    #collectBoundPoints(input, points) {
        if (!input) return;

        if (isArray(input)) {
            if (input.length >= 2 && typeof input[0] === 'number' && typeof input[1] === 'number') {
                points.push({ lat: Number(input[0]), lng: Number(input[1]) });
                return;
            }

            input.forEach((entry) => this.#collectBoundPoints(entry, points));
            return;
        }

        if (typeof input.lat === 'number' && typeof input.lng === 'number') {
            points.push({ lat: input.lat, lng: input.lng });
        }
    }

    #captureOverlayState(layer) {
        if (typeof layer.getPath === 'function') {
            return {
                type: 'polygon',
                paths: layer
                    .getPath()
                    .getArray()
                    .map((point) => ({ lat: point.lat(), lng: point.lng() })),
            };
        }

        if (typeof layer.getBounds === 'function') {
            const bounds = layer.getBounds();
            return {
                type: 'rectangle',
                bounds: {
                    north: bounds.getNorthEast().lat(),
                    east: bounds.getNorthEast().lng(),
                    south: bounds.getSouthWest().lat(),
                    west: bounds.getSouthWest().lng(),
                },
            };
        }

        if (typeof layer.getCenter === 'function' && typeof layer.getRadius === 'function') {
            const center = layer.getCenter();
            return {
                type: 'circle',
                center: { lat: center.lat(), lng: center.lng() },
                radius: layer.getRadius(),
            };
        }

        return null;
    }

    #restoreOverlayState(layer, state) {
        if (!layer || !state) return;

        if (state.type === 'polygon') {
            layer.setPath(state.paths);
            return;
        }

        if (state.type === 'rectangle') {
            layer.setBounds(state.bounds);
            return;
        }

        if (state.type === 'circle') {
            layer.setCenter(state.center);
            layer.setRadius(state.radius);
        }
    }

    /**
     * Convert a Google Maps overlay to the same geometry contract used by the
     * pre-agnostic Leaflet flow wherever possible.
     *
     * @param {*} overlay
     * @param {string} type
     * @returns {Object|null}
     */
    #overlayToGeoJson(overlay, type) {
        if (type === google.maps.drawing.OverlayType.POLYGON) {
            const path = overlay.getPath().getArray();
            const coords = path.map((p) => [p.lng(), p.lat()]);
            coords.push(coords[0]); // Close the ring
            return new Polygon([coords]);
        }

        if (type === google.maps.drawing.OverlayType.RECTANGLE) {
            const bounds = overlay.getBounds();
            const ne = bounds.getNorthEast();
            const sw = bounds.getSouthWest();
            const coords = [
                [sw.lng(), sw.lat()],
                [ne.lng(), sw.lat()],
                [ne.lng(), ne.lat()],
                [sw.lng(), ne.lat()],
                [sw.lng(), sw.lat()],
            ];

            return new Polygon([coords]);
        }

        if (type === google.maps.drawing.OverlayType.CIRCLE) {
            const center = overlay.getCenter();
            const radius = overlay.getRadius();
            return new Circle([center.lng(), center.lat()], radius);
        }

        if (type === google.maps.drawing.OverlayType.POLYLINE) {
            const path = overlay.getPath().getArray();
            const coords = path.map((p) => [p.lng(), p.lat()]);
            return new Feature({
                type: 'Feature',
                geometry: { type: 'LineString', coordinates: coords },
                properties: {},
            });
        }

        return null;
    }

    #geoJsonFromOverlay(overlay) {
        if (!overlay) return null;

        if (typeof overlay.getPath === 'function') {
            return this.#overlayToGeoJson(overlay, google.maps.drawing.OverlayType.POLYGON);
        }

        if (typeof overlay.getBounds === 'function' && typeof overlay.getCenter !== 'function') {
            return this.#overlayToGeoJson(overlay, google.maps.drawing.OverlayType.RECTANGLE);
        }

        if (typeof overlay.getCenter === 'function' && typeof overlay.getRadius === 'function') {
            return this.#overlayToGeoJson(overlay, google.maps.drawing.OverlayType.CIRCLE);
        }

        return null;
    }

    /**
     * Normalize cross-provider event names to Google Maps event names.
     *
     * @param {string} event
     * @returns {string}
     */
    #normalizeEvent(event) {
        const map = {
            click: 'click',
            dblclick: 'dblclick',
            rightclick: 'rightclick',
            moveend: 'idle',
            zoomend: 'zoom_changed',
            load: 'tilesloaded',
            contextmenu: 'rightclick',
        };
        return map[event] ?? event;
    }

    /**
     * Map normalized draw event name to Google Maps DrawingManager event.
     *
     * @param {string} normalizedEvent
     * @returns {string}
     */
    #drawEvent(normalizedEvent) {
        const map = {
            'draw:created': 'overlaycomplete',
            'draw:edited': 'overlaycomplete',
            'draw:deleted': 'overlaycomplete',
            'draw:drawstop': 'drawingmode_changed',
        };
        return map[normalizedEvent] ?? normalizedEvent;
    }

    /**
     * Normalize a Google Maps map event to a cross-provider event object.
     *
     * @param {string} event - Original event name
     * @param {*} gmEvent - Google Maps event object
     * @returns {Object}
     */
    #normalizeMapEvent(event, gmEvent) {
        if (!gmEvent) return { type: event };
        const latlng = gmEvent.latLng ? { lat: gmEvent.latLng.lat(), lng: gmEvent.latLng.lng() } : null;
        return {
            type: event,
            latlng,
            originalEvent: gmEvent.domEvent ?? gmEvent,
            _gmEvent: gmEvent,
        };
    }

    /**
     * Normalize a Google Maps drawing event.
     *
     * @param {string} event
     * @param {*} gmEvent
     * @returns {Object}
     */
    #normalizeDrawEvent(event, gmEvent) {
        return {
            type: event,
            layer: gmEvent?.overlay ?? null,
            layerType: gmEvent?.type ?? null,
            _gmEvent: gmEvent,
        };
    }

    /**
     * Fire a normalized event to all registered listeners.
     *
     * @param {string} event
     * @param {*} data
     */
    #fireNormalizedEvent(event, data) {
        const listeners = this._eventListeners.get(event) ?? [];
        listeners.forEach(({ handler }) => {
            try {
                handler(data);
            } catch (e) {
                debug(`[GoogleMapsAdapter] Error in event handler for "${event}": ${e.message}`);
            }
        });
    }

    #getToolbarTopOffset() {
        const topbar = document.querySelector('#map-topbar-container');
        const topbarHeight = topbar?.getBoundingClientRect?.().height ?? 0;
        return Math.max(16, Math.round(topbarHeight + 12));
    }

    /**
     * Render a DOM-based context menu at the given DOM coordinates.
     *
     * @param {MouseEvent} domEvent
     * @param {google.maps.LatLng} latLng
     * @param {Array} items
     */
    #renderContextMenu(domEvent, latLng, items) {
        const latitude = typeof latLng?.lat === 'function' ? latLng.lat() : latLng?.lat;
        const longitude = typeof latLng?.lng === 'function' ? latLng.lng() : latLng?.lng;
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;

        // Remove any existing context menu
        this.closeContextMenu();

        const menu = document.createElement('div');
        menu.className = 'fleetops-google-contextmenu';
        menu.style.cssText = `
            position: fixed;
            z-index: 9999;
            left: ${domEvent?.clientX ?? 0}px;
            top: ${domEvent?.clientY ?? 0}px;
        `;

        items.forEach((item) => {
            if (item.separator) {
                const sep = document.createElement('div');
                sep.className = 'fleetops-google-contextmenu-separator';
                menu.appendChild(sep);
                return;
            }
            const el = document.createElement('a');
            el.href = '#';
            el.className = 'fleetops-google-contextmenu-item';
            el.textContent = item.label;
            el.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                item.action({ latlng: { lat: latitude, lng: longitude }, domEvent });
                this.closeContextMenu();
            });
            menu.appendChild(el);
        });

        document.body.appendChild(menu);

        const close = (e) => {
            if (!menu.contains(e.target)) {
                menu.remove();
                document.removeEventListener('click', close);
            }
        };
        setTimeout(() => document.addEventListener('click', close), 0);
        this._contextMenuCleanup = () => {
            menu.remove();
            document.removeEventListener('click', close);
        };
    }

    #normalizeLatLngPoint(point) {
        if (point && typeof point.lat === 'number' && typeof point.lng === 'number') {
            return { lat: point.lat, lng: point.lng };
        }

        if (isArray(point) && point.length >= 2) {
            const lat = Number(point[0]);
            const lng = Number(point[1]);

            if (Number.isFinite(lat) && Number.isFinite(lng)) {
                return { lat, lng };
            }
        }

        return null;
    }

    #normalizeLinePath(coordinates) {
        if (!isArray(coordinates)) return null;

        const path = coordinates.map((point) => this.#normalizeLatLngPoint(point)).filter(Boolean);
        return path.length >= 2 ? path : null;
    }

    #normalizePolygonPaths(coordinates) {
        if (!isArray(coordinates) || coordinates.length === 0) return null;

        const firstPoint = coordinates[0];
        const hasNestedRings = isArray(firstPoint) && isArray(firstPoint[0]);
        const rings = hasNestedRings ? coordinates : [coordinates];

        const normalizedRings = rings.map((ring) => ring.map((point) => this.#normalizeLatLngPoint(point)).filter(Boolean)).filter((ring) => ring.length >= 3);

        if (normalizedRings.length === 0) {
            return null;
        }

        return normalizedRings.length === 1 ? normalizedRings[0] : normalizedRings;
    }

    #normalizeMarkerPosition(lat, lng) {
        const normalizedLat = Number(lat);
        const normalizedLng = Number(lng);

        if (!Number.isFinite(normalizedLat) || !Number.isFinite(normalizedLng)) {
            return null;
        }

        return { lat: normalizedLat, lng: normalizedLng };
    }

    #extractMarkerPosition(marker) {
        const position = marker?.position;
        if (!position) {
            return null;
        }

        if (typeof position.lat === 'function' && typeof position.lng === 'function') {
            const lat = position.lat();
            const lng = position.lng();

            if (Number.isFinite(lat) && Number.isFinite(lng)) {
                return { lat, lng };
            }
        }

        if (Number.isFinite(position.lat) && Number.isFinite(position.lng)) {
            return { lat: position.lat, lng: position.lng };
        }

        return null;
    }

    #setMarkerPosition(marker, position) {
        try {
            if (typeof marker.setPosition === 'function') {
                marker.setPosition(position);
            } else {
                marker.position = position;
            }
        } catch (error) {
            debug(`[GoogleMapsAdapter] Failed to set marker position: ${error.message}`);
        }
    }

    #createClassicMarker(lat, lng, options = {}) {
        const position = { lat, lng };
        const markerOptions = {
            map: this._map,
            position,
            title: options.title ?? options.tooltip ?? '',
            zIndex: options.zIndexOffset ?? 0,
        };

        if (options.waypointLabel) {
            markerOptions.icon = {
                url: buildWaypointMarkerSvg(options.waypointLabel, options.waypointColor ?? '#2563eb'),
                scaledSize: new google.maps.Size(34, 34),
                anchor: new google.maps.Point(17, 17),
            };
        } else if (options.iconUrl) {
            const size = options.iconSize ?? [24, 24];
            markerOptions.icon = {
                url: options.iconUrl,
                scaledSize: new google.maps.Size(size[0], size[1]),
            };
        }

        const marker = new google.maps.Marker(markerOptions);

        if (typeof options.onClick === 'function') {
            marker.addListener('click', options.onClick);
        }

        if (typeof options.onRightClick === 'function') {
            marker.addListener('rightclick', (event) => options.onRightClick(this.#normalizeMapEvent('rightclick', event)));
        }

        if (options.tooltip) {
            marker.__tooltipCleanup = this.#attachClassicMarkerTooltip(marker, options.tooltip, options.tooltipOptions ?? {});
        }

        return marker;
    }

    #attachClassicMarkerTooltip(marker, tooltipContent, options = {}) {
        if (!marker || !tooltipContent || !this._map) return null;

        let tooltipEl = null;

        const ensureTooltip = () => {
            if (tooltipEl) return tooltipEl;

            tooltipEl = document.createElement('div');
            tooltipEl.className = options.className || 'fleetops-google-hover-tooltip';

            tooltipEl.innerHTML = options.html ? tooltipContent : `<div class="fleetops-google-hover-tooltip__title">${tooltipContent}</div>`;

            Object.assign(tooltipEl.style, {
                position: 'fixed',
                display: 'none',
                zIndex: options.zIndex || 9999,
                pointerEvents: 'none',
            });

            document.body.appendChild(tooltipEl);

            return tooltipEl;
        };

        const positionTooltip = (event) => {
            const domEvent = event?.domEvent;

            if (!domEvent) return false;

            const el = ensureTooltip();

            const offsetX = options.offsetX ?? 12;
            const offsetY = options.offsetY ?? 12;

            el.style.left = `${domEvent.clientX + offsetX}px`;
            el.style.top = `${domEvent.clientY + offsetY}px`;

            return true;
        };

        const show = (event) => {
            const el = ensureTooltip();
            el.style.display = positionTooltip(event) ? 'block' : 'none';
        };

        const move = (event) => {
            if (!tooltipEl) return;

            tooltipEl.style.display = positionTooltip(event) ? 'block' : 'none';
        };

        const hide = () => {
            if (tooltipEl) {
                tooltipEl.style.display = 'none';
            }
        };

        const mouseOverListener = marker.addListener('mouseover', show);
        const mouseMoveListener = marker.addListener('mousemove', move);
        const mouseOutListener = marker.addListener('mouseout', hide);

        return () => {
            google.maps.event.removeListener(mouseOverListener);
            google.maps.event.removeListener(mouseMoveListener);
            google.maps.event.removeListener(mouseOutListener);

            tooltipEl?.remove();
            tooltipEl = null;
        };
    }

    #ensureTooltipOverlayView() {
        if (this._tooltipOverlayView || !this._map) {
            return this._tooltipOverlayView;
        }

        const overlayView = new google.maps.OverlayView();

        overlayView.onAdd = () => {};
        overlayView.draw = () => {};
        overlayView.onRemove = () => {};

        overlayView.setMap(this._map);

        this._tooltipOverlayView = overlayView;

        return overlayView;
    }
}
