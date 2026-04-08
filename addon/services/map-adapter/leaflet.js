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
import { debug } from '@ember/debug';
import { isNone } from '@ember/utils';

const L = window.leaflet || window.L;

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

    // ─── Lifecycle ─────────────────────────────────────────────────────────────

    initializeMap(element, options = {}) {
        if (this._map) {
            debug('[LeafletAdapter] Map already initialized, returning existing instance.');
            return this._map;
        }

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
        this._drawControl = null;
        this._drawFeatureGroup = null;
        this._tileLayer = null;
        debug('[LeafletAdapter] Map destroyed');
    }

    invalidateSize() {
        this._map?.invalidateSize(false);
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
        const c = this._map?.getCenter();
        return c ? { lat: c.lat, lng: c.lng } : { lat: 0, lng: 0 };
    }

    getBounds() {
        const b = this._map?.getBounds();
        if (!b) return [[0, 0], [0, 0]];
        return [
            [b.getSouth(), b.getWest()],
            [b.getNorth(), b.getEast()],
        ];
    }

    // ─── Markers ───────────────────────────────────────────────────────────────

    addMarker(id, lat, lng, options = {}) {
        if (!this._map) return null;

        const iconOptions = options.iconUrl
            ? L.icon({
                  iconUrl: options.iconUrl,
                  iconSize: options.iconSize ?? [24, 24],
                  iconAnchor: options.iconAnchor ?? [12, 12],
                  ...options.iconOptions,
              })
            : options.icon ?? undefined;

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
        layer.__hidden = true;
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

    enableDrawingMode(type) {
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
    }

    disableDrawingMode() {
        // Leaflet Draw disables on 'draw:created' or via the toolbar
        // Emit a synthetic stop event for listeners
        this._map?.fire('draw:drawstop');
    }

    showDrawControl() {
        if (!this._drawControl) return;
        const container = this._drawControl.getContainer?.();
        if (container) container.style.display = '';
    }

    hideDrawControl() {
        if (!this._drawControl) return;
        const container = this._drawControl.getContainer?.();
        if (container) container.style.display = 'none';
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
        this._drawFeatureGroup = featureGroup;
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
        this._map.on(normalized, handler);
    }

    off(event, handler) {
        if (!this._map) return;
        const normalized = this.#normalizeEvent(event);
        this._map.off(normalized, handler);
    }

    once(event, handler) {
        if (!this._map) return;
        const normalized = this.#normalizeEvent(event);
        this._map.once(normalized, handler);
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
