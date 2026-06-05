/**
 * LeafletAdapter
 *
 * Implements the MapAdapterInterface using the Leaflet library (via ember-leaflet).
 * This adapter wraps all existing Leaflet-specific logic, preserving 100% of
 * the original FleetOps map functionality while conforming to the provider-agnostic
 * interface.
 *
 * All Leaflet-specific plugins are supported:
 *   - leaflet-rotatedmarker  → setMarkerRotation()
 *   - leaflet.marker.slideto → updateMarkerPosition() with animation
 *   - leaflet-draw           → enableDrawingMode() / showDrawControl()
 *   - leaflet-contextmenu    → registerContextMenu()
 *
 * @module services/map-adapter/leaflet
 */
import MapAdapterInterface from '../map-adapter-interface';
import { isArray } from '@ember/array';
import { debug } from '@ember/debug';
import { isNone } from '@ember/utils';
import { next } from '@ember/runloop';
import { createGeoJsonFromLayer } from '../../utils/leaflet-to-geojson';
import { waypointIconHtml } from '../../utils/route-colors';
import ensureLeafletDrawEditNamespace from '../../utils/leaflet-draw-namespace-guard';
import ensureLeafletPluginsReady from '../../utils/leaflet-plugin-loader';

const L = window.leaflet || window.L;

function ensureLeafletConstructorNamespace() {
    ensureLeafletDrawEditNamespace(L);
    ensureLeafletDrawEditNamespace();
}

export default class LeafletAdapter extends MapAdapterInterface {
    // ─── Internal State ────────────────────────────────────────────────────────

    /** @type {L.Map} */
    _map = null;

    /** @type {Map<string, L.Marker>} */
    _markers = new Map();

    /** @type {Map<string, L.Layer>} */
    _overlays = new Map();

    /** @type {Map<string, L.Popup>} */
    _popups = new Map();

    /** @type {Map<string, L.GeoJSON>} */
    _geojsonLayers = new Map();

    /** @type {L.Control.Draw|null} */
    _drawControl = null;

    /** @type {L.FeatureGroup|null} */
    _drawFeatureGroup = null;

    /** @type {L.TileLayer|null} */
    _tileLayer = null;

    /** @type {Map<string, Function[]>} Normalized event → [handler, wrappedHandler] pairs */
    _eventHandlers = new Map();

    /** @type {Object|null} */
    _drawControlConfig = null;

    // ─── Lifecycle ─────────────────────────────────────────────────────────────

    initializeMap(element, options = {}) {
        if (this._map) {
            debug('[LeafletAdapter] Map already initialized, returning existing instance.');
            return this._map;
        }

        ensureLeafletConstructorNamespace();

        const mapOptions = {
            center: [options.lat ?? 1.3521, options.lng ?? 103.8198],
            zoom: options.zoom ?? 12,
            zoomControl: options.zoomControl ?? false,
            contextmenu: options.contextmenu ?? false,
            contextmenuWidth: options.contextmenuWidth ?? 140,
            ...options.leafletOptions,
        };

        this._map = L.map(element, mapOptions);

        // Add default tile layer if a URL is provided
        if (options.tileUrl) {
            this._tileLayer = L.tileLayer(options.tileUrl, options.tileOptions ?? {}).addTo(this._map);
        }

        debug('[LeafletAdapter] Map initialized');
        return this._map;
    }

    setMapInstance(mapInstance) {
        this._map = mapInstance;
        return mapInstance;
    }

    destroyMap() {
        try {
            this._map?.remove();
        } catch (e) {
            debug('[LeafletAdapter] Error removing map: ' + e.message);
        }
        this._map = null;
        this._markers.clear();
        this._overlays.clear();
        this._popups.clear();
        this._geojsonLayers.clear();
        this._routingControls.clear();
        this._drawControl = null;
        this._drawFeatureGroup = null;
        this._tileLayer = null;
        this._eventHandlers.clear();
        debug('[LeafletAdapter] Map destroyed');
    }

    invalidateSize() {
        this._map?.invalidateSize(false);
    }

    async ensureInteractive({ timeoutMs = 8000, map = null } = {}) {
        await ensureLeafletPluginsReady({ timeoutMs });
        return map ?? this._map;
    }

    // ─── Viewport ──────────────────────────────────────────────────────────────

    setCenter(lat, lng, zoom) {
        if (!this._map) return;
        if (zoom !== undefined) {
            this._map.setView([lat, lng], zoom);
        } else {
            this._map.setView([lat, lng]);
        }
    }

    flyTo(lat, lng, zoom, options = {}) {
        if (!this._map) return;
        this._map.flyTo([lat, lng], zoom, options);
    }

    fitBounds(bounds, options = {}) {
        if (!this._map) return;
        // Normalize bounds: accept [[swLat, swLng], [neLat, neLng]] or L.LatLngBounds
        const leafletBounds = bounds instanceof L.LatLngBounds ? bounds : L.latLngBounds(bounds);
        this._map.fitBounds(leafletBounds, options);
    }

    panTo(lat, lng, options = {}) {
        if (!this._map) return;
        this._map.panTo([lat, lng], options);
    }

    zoomIn() {
        this._map?.zoomIn();
    }

    zoomOut() {
        this._map?.zoomOut();
    }

    getZoom() {
        return this._map?.getZoom() ?? 0;
    }

    getCenter() {
        if (!this._map?._loaded) {
            return { lat: 0, lng: 0 };
        }

        try {
            const c = this._map.getCenter();
            return c ? { lat: c.lat, lng: c.lng } : { lat: 0, lng: 0 };
        } catch {
            return { lat: 0, lng: 0 };
        }
    }

    getBounds() {
        const b = this._map?.getBounds();
        if (!b)
            return [
                [0, 0],
                [0, 0],
            ];
        return [
            [b.getSouth(), b.getWest()],
            [b.getNorth(), b.getEast()],
        ];
    }

    // ─── Markers ───────────────────────────────────────────────────────────────

    addMarker(id, lat, lng, options = {}) {
        if (!this._map) return null;
        ensureLeafletConstructorNamespace();

        const iconOptions = options.iconUrl
            ? L.icon({
                  iconUrl: options.iconUrl,
                  iconSize: options.iconSize ?? [24, 24],
                  iconAnchor: options.iconAnchor ?? [12, 12],
                  ...options.iconOptions,
              })
            : (options.icon ?? undefined);

        const markerOptions = {
            title: options.title,
            alt: options.alt,
            draggable: options.draggable ?? false,
            rotationAngle: options.rotationAngle ?? 0,
            zIndexOffset: options.zIndexOffset ?? 0,
        };

        if (iconOptions) {
            markerOptions.icon = iconOptions;
        }

        const marker = L.marker([lat, lng], markerOptions).addTo(this._map);

        if (options.popup) {
            marker.bindPopup(options.popup);
        }

        if (options.tooltip) {
            marker.bindTooltip(options.tooltip, options.tooltipOptions ?? {});
        }

        if (typeof options.onClick === 'function') {
            marker.on('click', options.onClick);
        }

        this._markers.set(id, marker);
        return marker;
    }

    updateMarkerPosition(id, lat, lng, animated = true, duration = 500) {
        const marker = this._markers.get(id);
        if (!marker) return;

        if (animated && typeof marker.slideTo === 'function') {
            marker.slideTo([lat, lng], { duration });
        } else {
            marker.setLatLng([lat, lng]);
        }
    }

    setMarkerRotation(id, degrees) {
        const marker = this._markers.get(id);
        if (!marker) return;
        if (typeof marker.setRotationAngle === 'function') {
            marker.setRotationAngle(degrees);
        }
    }

    removeMarker(id) {
        const marker = this._markers.get(id);
        if (marker) {
            try {
                marker.remove();
            } catch (e) {
                debug('[LeafletAdapter] Error removing marker: ' + e.message);
            }
            this._markers.delete(id);
        }
    }

    // ─── Overlays ──────────────────────────────────────────────────────────────

    addPolygon(id, coordinates, options = {}) {
        if (!this._map) return null;
        ensureLeafletConstructorNamespace();
        const polygon = L.polygon(coordinates, {
            color: options.color ?? '#3388ff',
            fillColor: options.fillColor ?? options.color ?? '#3388ff',
            fillOpacity: options.fillOpacity ?? 0.2,
            weight: options.weight ?? 3,
            ...options.leafletOptions,
        }).addTo(this._map);

        if (options.tooltip) {
            polygon.bindTooltip(options.tooltip, options.tooltipOptions ?? { permanent: true, sticky: true });
        }

        this._overlays.set(id, polygon);
        return polygon;
    }

    addPolyline(id, coordinates, options = {}) {
        if (!this._map) return null;
        ensureLeafletConstructorNamespace();
        const polyline = L.polyline(coordinates, {
            color: options.color ?? '#3388ff',
            weight: options.weight ?? 3,
            opacity: options.opacity ?? 1,
            ...options.leafletOptions,
        }).addTo(this._map);

        this._overlays.set(id, polyline);
        return polyline;
    }

    addCircle(id, lat, lng, radiusMeters, options = {}) {
        if (!this._map) return null;
        ensureLeafletConstructorNamespace();
        const circle = L.circle([lat, lng], {
            radius: radiusMeters,
            color: options.color ?? '#3388ff',
            fillColor: options.fillColor ?? options.color ?? '#3388ff',
            fillOpacity: options.fillOpacity ?? 0.2,
            weight: options.weight ?? 3,
            ...options.leafletOptions,
        }).addTo(this._map);

        this._overlays.set(id, circle);
        return circle;
    }

    removeOverlay(id) {
        const overlay = this._overlays.get(id);
        if (overlay) {
            try {
                overlay.remove();
            } catch (e) {
                debug('[LeafletAdapter] Error removing overlay: ' + e.message);
            }
            this._overlays.delete(id);
        }
    }

    addRoutingControl(route, options = {}) {
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
                          leafletOptions: {
                              lineCap: style.lineCap,
                              lineJoin: style.lineJoin,
                              dashArray: style.dashArray,
                              ...(options.polylineOptions?.leafletOptions ?? {}),
                          },
                      },
                  }))
                : [
                      {
                          id: `${handleId}:polyline:0`,
                          options: {
                              color: options.polylineOptions?.color ?? options.color ?? '#2563eb',
                              weight: options.polylineOptions?.weight ?? 4,
                              opacity: options.polylineOptions?.opacity ?? 0.85,
                              leafletOptions: options.polylineOptions?.leafletOptions ?? {},
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

        const markerWaypoints = options.markerWaypoints ?? route.waypoints ?? [];

        if (!options.suppressMarkers) {
            markerWaypoints.forEach((waypoint, index) => {
                const markerId = `${handleId}:marker:${index}`;
                const markerOptions = this.#buildRouteMarkerOptions(waypoint, index, route, options);
                if (markerOptions === null) return;

                this.addMarker(markerId, waypoint[0], waypoint[1], markerOptions);
                markerIds.push(markerId);
            });
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

        const isBounds = options.isBounds === true || waypointsOrBounds instanceof L.LatLngBounds;
        const bounds = isBounds ? waypointsOrBounds : null;
        const waypoints = isBounds ? null : waypointsOrBounds;
        const paddingBottomRight = options.paddingBottomRight ?? [300, 0];
        const singlePointZoom = options.singlePointZoom ?? 18;
        const maxZoom = options.maxZoom ?? (isArray(waypoints) && waypoints.length === 2 ? 15 : 14);
        const panBy = options.panBy ?? [0, 0];

        if (isArray(waypoints) && waypoints.length === 1) {
            this._map.flyTo(waypoints[0], singlePointZoom, { animate: true });
            this._map.once('moveend', () => this.panBy(panBy[0], panBy[1]));
            return true;
        }

        if (bounds || (isArray(waypoints) && waypoints.length > 1)) {
            this.fitBounds(bounds ?? waypoints, {
                paddingBottomRight,
                maxZoom,
                animate: true,
            });
            this._map.once('moveend', () => this.panBy(panBy[0], panBy[1]));
            return true;
        }

        return null;
    }

    removeLayer(layer) {
        if (!layer) return;

        try {
            if (typeof layer.remove === 'function') {
                layer.remove();
            } else {
                this._map?.removeLayer?.(layer);
            }
        } catch (e) {
            debug('[LeafletAdapter] Error removing layer: ' + e.message);
        }

        if (this._drawFeatureGroup?.hasLayer?.(layer)) {
            this._drawFeatureGroup.removeLayer(layer);
        }
    }

    // ─── Layer Visibility ──────────────────────────────────────────────────────

    showLayer(layer, { soft = false } = {}) {
        if (!this._map || !layer) return;
        if (soft) {
            if (layer.setStyle) {
                const fillOpacity = layer.options?.fillOpacity ?? 0.2;
                const hasFill = !isNone(layer.options?.fill);
                layer.setStyle({ opacity: 1, fillOpacity: hasFill ? fillOpacity : 0 });
            } else if (layer.setOpacity) {
                layer.setOpacity(1);
            }
            layer.__hidden = false;
            return;
        }
        const el = this.#getLayerEl(layer);
        if (el) el.style.display = '';
        this.#showOverlays(layer);
        layer.__hidden = false;
    }

    hideLayer(layer, { soft = false } = {}) {
        if (!this._map || !layer) return;
        if (soft) {
            if (layer.setStyle) layer.setStyle({ opacity: 0, fillOpacity: 0 });
            else if (layer.setOpacity) layer.setOpacity(0);
            layer.__hidden = true;
            return;
        }
        const el = this.#getLayerEl(layer);
        if (el) el.style.display = 'none';
        this.#hideOverlays(layer, true);
        layer.__hidden = true;
    }

    #hideOverlays(layer, remember = true) {
        next(() => {
            const tt = layer.getTooltip?.() ?? layer._tooltip ?? null;
            const pp = layer.getPopup?.() ?? layer._popup ?? null;

            if (remember) {
                layer.__hadOpenTooltip = !!(tt && tt.isOpen && tt.isOpen());
                layer.__hadOpenPopup = !!(pp && pp.isOpen && pp.isOpen());
            }

            try {
                if (tt) layer.closeTooltip?.();
            } catch {
                // noop
            }
            try {
                if (pp) layer.closePopup?.();
            } catch {
                // noop
            }

            if (tt?._container) tt._container.style.display = 'none';
            if (pp?._container) pp._container.style.display = 'none';
        });
    }

    #showOverlays(layer) {
        const tt = layer.getTooltip?.() ?? layer._tooltip ?? null;
        const pp = layer.getPopup?.() ?? layer._popup ?? null;

        if (tt?._container) tt._container.style.display = '';
        if (pp?._container) pp._container.style.display = '';

        if (tt) {
            const shouldOpen = layer.__hadOpenTooltip || tt.options?.permanent;
            if (shouldOpen) {
                try {
                    layer.openTooltip?.();
                } catch {
                    // noop
                }
            }
        }

        if (pp && layer.__hadOpenPopup) {
            try {
                layer.openPopup?.();
            } catch {
                // noop
            }
        }

        delete layer.__hadOpenTooltip;
        delete layer.__hadOpenPopup;
    }

    isLayerHidden(layer) {
        if (!layer) return true;
        if (layer.__hidden) return true;
        const el = this.#getLayerEl(layer);
        return el ? el.style.display === 'none' : false;
    }

    isLayerVisible(layer) {
        return !this.isLayerHidden(layer);
    }

    // ─── Drawing Tools ─────────────────────────────────────────────────────────

    enableDrawingMode(type, options = {}) {
        if (!this._map || !this._drawControl) {
            debug('[LeafletAdapter] enableDrawingMode called before draw control was created');
            return;
        }
        const modeMap = {
            polygon: L.Draw?.Polygon,
            circle: L.Draw?.Circle,
            rectangle: L.Draw?.Rectangle,
            polyline: L.Draw?.Polyline,
            marker: L.Draw?.Marker,
        };
        const DrawClass = modeMap[type];
        if (DrawClass) {
            new DrawClass(this._map).enable();
        }

        if (typeof options.onCreate === 'function') {
            this.once('draw:created', options.onCreate);
        }
    }

    disableDrawingMode() {
        // Leaflet Draw disables on 'draw:created' or via the toolbar
        // Emit a synthetic stop event for listeners
        this._map?.fire('draw:drawstop');
    }

    showDrawControl(config = {}) {
        this._drawControlConfig = config;
        if (!this._drawControl || !this._map) return;
        if (!this._drawControl._map) {
            this._drawControl.addTo(this._map);
        }

        const container = this._drawControl.getContainer?.();
        if (container) container.style.display = '';

        if (config?.defaultMode) {
            this.enableDrawingMode(config.defaultMode);
        }
    }

    hideDrawControl() {
        if (!this._drawControl || !this._map) return;
        const container = this._drawControl.getContainer?.();
        if (container) container.style.display = 'none';
        if (this._drawControl._map) {
            this._map.removeControl(this._drawControl);
        }
    }

    /**
     * Store a reference to the Leaflet Draw control created by ember-leaflet.
     * Called by the live-map component after draw-control creation.
     *
     * @param {L.Control.Draw} drawControl
     * @param {L.FeatureGroup} featureGroup
     */
    setDrawControl(drawControl, featureGroup) {
        this._drawControl = drawControl;
        if (featureGroup) {
            this._drawFeatureGroup = featureGroup;
        }

        if (this._drawControlConfig) {
            this.showDrawControl(this._drawControlConfig);
        }
    }

    registerMarker(id, markerObject) {
        return super.registerMarker(id, markerObject);
    }

    registerPolygon(id, polygonObject) {
        return super.registerPolygon(id, polygonObject);
    }

    getContextMenuItems(target) {
        return target?.contextmenuItems ?? [];
    }

    showCoordinates(event) {
        const wrappedLatLng = event?.latlng?.wrap?.() ?? event?.latlng;

        return {
            lat: wrappedLatLng?.lat,
            lng: wrappedLatLng?.lng,
        };
    }

    centerMap(event) {
        if (event?.latlng) {
            this._map?.panTo(event.latlng);
        }
    }

    toggleDrawControl() {
        if (!this._drawControl || !this._map) return;

        if (this._drawControl._map) {
            this.hideDrawControl();
            return;
        }

        this.showDrawControl(this._drawControlConfig ?? {});
    }

    editPolygon(originalLayer, { focusBounds = null } = {}) {
        const map = this._map;
        const draw = this._drawControl;
        const group = this._drawFeatureGroup;
        if (!map || !draw || !group || !originalLayer) {
            return Promise.resolve({ type: 'unsupported' });
        }

        this.showLayer(originalLayer, { soft: false });

        const latlngs = originalLayer.getLatLngs?.();
        if (!latlngs || !latlngs.length) {
            return Promise.reject(new Error('LeafletAdapter editPolygon: layer has no coordinates'));
        }

        ensureLeafletConstructorNamespace();
        const proxy = L.polygon(latlngs, {
            color: originalLayer.options?.color || '#3388ff',
            weight: 3,
            opacity: 0.9,
            fill: true,
            fillOpacity: originalLayer.options?.fillOpacity ?? 0.2,
        });

        const wasVisible = !originalLayer.__hidden;
        this.hideLayer(originalLayer, { soft: false });
        group.addLayer(proxy);

        if (focusBounds) {
            try {
                map.fitBounds(focusBounds, { paddingBottomRight: [0, 0], maxZoom: 16, animate: true });
            } catch {
                // noop
            }
        }

        this.showDrawControl({ allowEdit: true, allowDelete: true });
        const editHandler = draw?._toolbars?.edit?._modes?.edit?.handler;
        if (!editHandler) {
            group.removeLayer(proxy);
            if (wasVisible) {
                this.showLayer(originalLayer, { soft: false });
            }
            return Promise.reject(new Error('LeafletAdapter editPolygon: edit handler unavailable'));
        }

        editHandler.enable();

        return new Promise((resolve) => {
            let settled = false;

            const cleanup = (result) => {
                if (settled) return;
                settled = true;

                try {
                    editHandler.disable();
                } catch {
                    // noop
                }

                try {
                    group.removeLayer(proxy);
                } catch {
                    // noop
                }

                try {
                    proxy.remove();
                } catch {
                    // noop
                }

                if (wasVisible) {
                    this.showLayer(originalLayer, { soft: false });
                }

                this.hideDrawControl();
                resolve(result);
            };

            const onEdited = (evt) => {
                let geoJson = null;
                try {
                    const edited = proxy.getLatLngs?.();
                    if (edited?.length) {
                        originalLayer.setLatLngs(edited);
                        originalLayer.redraw?.();
                        geoJson = createGeoJsonFromLayer(originalLayer, { layerType: 'polygon' });
                    }
                } catch {
                    // noop
                }

                cleanup({
                    type: 'edited',
                    layer: originalLayer,
                    proxy,
                    event: evt,
                    geoJson,
                    toGeoJSON: () => geoJson,
                });
            };

            const onEditStop = () => cleanup({ type: 'cancel' });

            map.once('draw:edited', onEdited);
            map.once('draw:editstop', onEditStop);
        });
    }

    panBy(x, y = 0) {
        this._map?.panBy?.([x, y]);
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
                        icon: L.divIcon({
                            className: 'fleetops-waypoint-marker',
                            html: waypointIconHtml(customOptions.waypointLabel, customOptions.waypointColor ?? '#2563eb'),
                            iconSize: customOptions.iconSize ?? [32, 32],
                            iconAnchor: customOptions.iconAnchor ?? [16, 16],
                            popupAnchor: customOptions.popupAnchor ?? [0, -20],
                        }),
                    };
                }

                return customOptions;
            }
        }

        return {
            iconUrl: '/assets/images/marker-icon.png',
            iconSize: [25, 41],
            iconAnchor: [12, 41],
            iconOptions: {
                iconRetinaUrl: '/assets/images/marker-icon-2x.png',
                shadowUrl: '/assets/images/marker-shadow.png',
            },
            draggable: false,
            ...options.markerOptions,
        };
    }

    // ─── Popups ────────────────────────────────────────────────────────────────

    openPopup(id, lat, lng, htmlContent) {
        if (!this._map) return null;
        const popup = L.popup().setLatLng([lat, lng]).setContent(htmlContent).openOn(this._map);
        this._popups.set(id, popup);
        return popup;
    }

    closePopup(id) {
        const popup = this._popups.get(id);
        if (popup) {
            this._map?.closePopup(popup);
            this._popups.delete(id);
        }
    }

    // ─── Context Menus ─────────────────────────────────────────────────────────

    registerContextMenu(target, items) {
        if (!this._map) return;

        if (target === 'map') {
            // Use leaflet-contextmenu plugin if available
            if (typeof this._map.contextmenu?.addItem === 'function') {
                items.forEach((item) => {
                    if (item.separator) {
                        this._map.contextmenu.addItem({ separator: true });
                    } else {
                        this._map.contextmenu.addItem({
                            text: item.label,
                            callback: item.action,
                            icon: item.icon,
                        });
                    }
                });
            } else {
                // Fallback: listen to contextmenu event and render a custom DOM menu
                this._map.on('contextmenu', (e) => this.#renderDomContextMenu(e.latlng, e.originalEvent, items));
            }
        } else {
            const marker = this._markers.get(target);
            if (marker && typeof marker.bindContextMenu === 'function') {
                marker.bindContextMenu({ contextmenu: true, contextmenuItems: items.map((i) => ({ text: i.label, callback: i.action })) });
            }
        }
    }

    removeContextMenu(target) {
        if (target === 'map') {
            this._map?.contextmenu?.removeAllItems?.();
        } else {
            const marker = this._markers.get(target);
            marker?.unbindContextMenu?.();
        }
    }

    // ─── Events ────────────────────────────────────────────────────────────────

    on(event, handler) {
        if (!this._map) return;
        const normalized = this.#normalizeEvent(event);
        const wrappedHandler = normalized.startsWith('draw:') ? (payload) => handler(this.#normalizeDrawEvent(event, payload)) : handler;

        this._map.on(normalized, wrappedHandler);

        if (!this._eventHandlers.has(event)) {
            this._eventHandlers.set(event, []);
        }

        this._eventHandlers.get(event).push({ handler, wrappedHandler });
    }

    off(event, handler) {
        if (!this._map) return;
        const normalized = this.#normalizeEvent(event);
        const entries = this._eventHandlers.get(event) ?? [];
        const entry = entries.find((item) => item.handler === handler);
        const wrappedHandler = entry?.wrappedHandler ?? handler;

        this._map.off(normalized, wrappedHandler);

        if (entry) {
            this._eventHandlers.set(
                event,
                entries.filter((item) => item !== entry)
            );
        }
    }

    once(event, handler) {
        if (!this._map) return;
        const normalized = this.#normalizeEvent(event);
        const wrappedHandler = normalized.startsWith('draw:') ? (payload) => handler(this.#normalizeDrawEvent(event, payload)) : handler;

        this._map.once(normalized, wrappedHandler);
    }

    // ─── Utilities ─────────────────────────────────────────────────────────────

    distanceBetween(lat1, lng1, lat2, lng2) {
        if (this._map) {
            return this._map.distance([lat1, lng1], [lat2, lng2]);
        }
        // Haversine fallback
        return L.latLng(lat1, lng1).distanceTo(L.latLng(lat2, lng2));
    }

    addGeoJson(id, geojson, options = {}) {
        if (!this._map) return null;
        const layer = L.geoJSON(geojson, options).addTo(this._map);
        this._geojsonLayers.set(id, layer);
        return layer;
    }

    removeGeoJson(id) {
        const layer = this._geojsonLayers.get(id);
        if (layer) {
            layer.remove();
            this._geojsonLayers.delete(id);
        }
    }

    setTileLayer(url, options = {}) {
        if (!this._map) return;
        if (this._tileLayer) {
            this._tileLayer.remove();
        }
        this._tileLayer = L.tileLayer(url, options).addTo(this._map);
    }

    // ─── Private ───────────────────────────────────────────────────────────────

    #getLayerEl(layer) {
        return layer?._icon ?? layer?._path ?? layer?._container ?? null;
    }

    /**
     * Normalize cross-provider event names to Leaflet event names.
     * @param {string} event
     * @returns {string}
     */
    #normalizeEvent(event) {
        const map = {
            rightclick: 'contextmenu',
            'draw:created': 'draw:created',
            'draw:edited': 'draw:edited',
            'draw:deleted': 'draw:deleted',
        };
        return map[event] ?? event;
    }

    #normalizeDrawEvent(event, payload) {
        if (event === 'draw:created' && payload?.layer) {
            const layerType = payload.layerType ?? payload.type ?? null;
            const geoJson = createGeoJsonFromLayer(payload.layer, { layerType });

            return {
                ...payload,
                type: event,
                layer: payload.layer,
                layerType,
                geoJson,
                toGeoJSON: () => geoJson,
            };
        }

        return {
            ...payload,
            type: event,
        };
    }

    /**
     * Render a simple DOM-based context menu as a fallback when the
     * leaflet-contextmenu plugin is not available.
     *
     * @param {L.LatLng} latlng
     * @param {MouseEvent} originalEvent
     * @param {Array} items
     */
    #renderDomContextMenu(latlng, originalEvent, items) {
        // Remove any existing context menu
        document.querySelector('.fleetops-map-contextmenu')?.remove();

        const menu = document.createElement('div');
        menu.className = 'fleetops-map-contextmenu';
        menu.style.cssText = `
            position: fixed;
            z-index: 9999;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            padding: 4px 0;
            min-width: 160px;
            font-size: 13px;
            left: ${originalEvent.clientX}px;
            top: ${originalEvent.clientY}px;
        `;

        items.forEach((item) => {
            if (item.separator) {
                const sep = document.createElement('div');
                sep.style.cssText = 'height: 1px; background: #e2e8f0; margin: 4px 0;';
                menu.appendChild(sep);
                return;
            }
            const el = document.createElement('div');
            el.textContent = item.label;
            el.style.cssText = 'padding: 6px 14px; cursor: pointer; color: #374151;';
            el.addEventListener('mouseenter', () => (el.style.background = '#f3f4f6'));
            el.addEventListener('mouseleave', () => (el.style.background = ''));
            el.addEventListener('click', () => {
                item.action({ latlng, originalEvent });
                menu.remove();
            });
            menu.appendChild(el);
        });

        document.body.appendChild(menu);

        // Close on next click
        const close = (e) => {
            if (!menu.contains(e.target)) {
                menu.remove();
                document.removeEventListener('click', close);
            }
        };
        setTimeout(() => document.addEventListener('click', close), 0);
    }
}
