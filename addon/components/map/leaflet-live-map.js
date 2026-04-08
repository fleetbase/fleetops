import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, set } from '@ember/object';
import { isArray } from '@ember/array';
import { debug } from '@ember/debug';
import { guidFor } from '@ember/object/internals';
import { camelize, capitalize, dasherize } from '@ember/string';
import { singularize, pluralize } from 'ember-inflector';
import { all } from 'rsvp';
import { task } from 'ember-concurrency';
import getModelName from '@fleetbase/ember-core/utils/get-model-name';
import { colorForId, darkenColor, routeStyleForStatus } from '../../utils/route-colors';

const L = window.leaflet || window.L;

export default class MapLeafletLiveMapComponent extends Component {
    @service leafletMapManager;
    @service leafletLayerVisibilityManager;
    @service leafletContextmenuManager;
    @service resourceContextPanel;
    @service serviceAreaActions;
    @service zoneActions;
    @service placeActions;
    @service vehicleActions;
    @service driverActions;
    @service movementTracker;
    @service geofence;
    @service location;
    @service fetch;
    @service abilities;
    @service intl;
    @service universe;
    @service('universe/menu-service') menuService;

    /** properties */
    id = guidFor(this);

    /** tracked properties */
    @tracked ready = false;
    @tracked zoom = this.getValidZoom();
    @tracked latitude = this.location.getLatitude();
    @tracked longitude = this.location.getLongitude();
    @tracked contextmenuItems = [];
    @tracked tileUrl = 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png';
    @tracked theme = 'light';
    @tracked routes = [];
    @tracked drivers = [];
    @tracked vehicles = [];
    @tracked places = [];

    /** Internal map of route id -> L.LayerGroup for live route polylines */
    _liveRouteLayerGroups = new Map();

    constructor() {
        super(...arguments);

        // Store bound function reference for proper cleanup
        this._locationUpdateHandler = this.#handleLocationUpdate.bind(this);

        // Listen for location updates from the location service
        this.universe.on('user.located', this._locationUpdateHandler);

        // Ensure we have valid coordinates on initialization
        this.#updateCoordinatesFromLocation();
    }

    willDestroy() {
        super.willDestroy();

        // Clean up event listener using stored reference
        if (this._locationUpdateHandler) {
            this.universe.off('user.located', this._locationUpdateHandler);
            this._locationUpdateHandler = null;
        }

        // Remove all live route polyline layers from the map
        this.#clearLiveRouteLayerGroups();
    }

    @action didLoad({ target: map }) {
        this.#setMap(map);
        this.#createMapContextMenu(map);
        this.trigger('onLoad', ...arguments);
        this.load.perform();

        // Listen for map move/zoom events to trigger viewport-based resource reload
        map.on('moveend', () => this.reloadResourcesInViewport.perform());
        map.on('zoomend', () => this.reloadResourcesInViewport.perform());
    }

    @action trigger(name, ...rest) {
        if (typeof this[name] === 'function') {
            this[name](...rest);
        }
        if (typeof this.args[name] === 'function') {
            this.args[name](...rest);
        }
        // Fire as universe event
        const uevent = dasherize(name);
        this.universe.trigger(`fleet-ops.live-map.${uevent}`, ...rest);
    }

    @action didCreateDrawControl(drawControl) {
        this.leafletMapManager.setDrawControl(drawControl);
        this.trigger('onDrawControlCreated', ...arguments);
    }

    @action didCreateDrawControlFeatureGroup(featureGroup) {
        this.leafletMapManager.setDrawControlFeatureGroup(featureGroup);
        this.trigger('onDrawFeatureGroupCreated', ...arguments);
    }

    @action onDriverAdded(driver, { target: layer }) {
        this.#setResourceLayer(driver, layer);
        this.#createDriverContextMenu(driver, layer);
        this.movementTracker.track(driver);
    }

    @action onDriverClicked(driver) {
        this.driverActions.panel.view(driver, {
            size: 'xs',
            onOpen: () => {
                this.map.once('moveend', () => {
                    this.map.panBy([200, 0]);
                });
            },
        });
    }

    @action onVehicleAdded(vehicle, { target: layer }) {
        this.#setResourceLayer(vehicle, layer);
        this.#createVehicleContextMenu(vehicle, layer);
        this.movementTracker.track(vehicle);
    }

    @action onVehicleClicked(vehicle) {
        this.vehicleActions.panel.view(vehicle, {
            size: 'xs',
            onOpen: () => {
                this.map.once('moveend', () => {
                    this.map.panBy([200, 0]);
                });
            },
        });
    }

    @action onPlaceAdded(place, { target: layer }) {
        this.#setResourceLayer(place, layer);
    }

    @action onPlaceClicked(place) {
        this.placeActions.panel.view(place, {
            size: 'xs',
            onOpen: () => {
                this.map.once('moveend', () => {
                    this.map.panBy([200, 0]);
                });
            },
        });
    }

    @action onServiceAreaLayerAdded(serviceArea, { target: layer }) {
        this.#setResourceLayer(serviceArea, layer, { hidden: true });
        this.#createServiceAreaContextMenu(serviceArea, layer);
    }

    @action onZoneLayerAdd(zone, { target: layer }) {
        this.#setResourceLayer(zone, layer, { hidden: true });
        this.#createZoneContextMenu(zone, layer);
    }

    /** load resources and wait for stuff here and trigger map ready **/
    @task *load() {
        try {
            // Get initial map bounds for spatial filtering
            const bounds = this.map ? this.map.getBounds() : null;
            const params = bounds
                ? {
                      bounds: [bounds.getSouth(), bounds.getWest(), bounds.getNorth(), bounds.getEast()],
                  }
                : {};

            const data = yield all([
                this.loadResource.perform('routes'),
                this.loadResource.perform('vehicles', { params }),
                this.loadResource.perform('drivers', { params }),
                this.loadResource.perform('places', { params }),
                this.loadResource.perform('service-areas'),
            ]);

            this.#createMapContextMenu(this.map);
            this.trigger('onLoaded', { map: this.map, data });
            this.ready = true;

            // Render color-coded polylines for all loaded routes
            this.#renderLiveRoutes(this.routes);
        } catch (err) {
            debug('Failed to load live map: ' + err.message);
        }
    }

    @task({ restartable: true }) *reloadResourcesInViewport() {
        if (!this.map) {
            return;
        }

        // Get current map bounds
        const bounds = this.map.getBounds();
        const params = {
            bounds: [bounds.getSouth(), bounds.getWest(), bounds.getNorth(), bounds.getEast()],
        };

        // Reload spatially-filtered resources (drivers, vehicles, places)
        // Orders, routes, and service-areas are not spatially filtered
        try {
            yield all([this.loadResource.perform('vehicles', { params }), this.loadResource.perform('drivers', { params }), this.loadResource.perform('places', { params })]);
        } catch (err) {
            debug('Failed to reload resources in viewport: ' + err.message);
        }
    }

    @task *loadResource(path, options = {}) {
        if (this.abilities.cannot(`fleet-ops list ${path}`)) return [];

        if (path === 'service-areas') {
            const serviceAreas = yield this.serviceAreaActions.loadAll.perform();
            this.trigger('onServiceAreasLoaded', serviceAreas);
            return serviceAreas;
        }

        const name = camelize(path);
        const callback = `on${capitalize(name)}Loaded`;
        const params = options.params ?? {};
        const url = `fleet-ops/live/${path}`;

        try {
            const data = yield this.fetch.get(url, params, { normalizeToEmberData: true, normalizeModelType: singularize(dasherize(name)) });

            this.trigger(callback, data);
            this[name] = data;

            if (typeof options.onLoaded === 'function') {
                options.onLoaded(data);
            }

            return data;
        } catch (err) {
            debug('Failed to load resource: ' + err.message);
            if (typeof options.onFailure === 'function') {
                options.onFailure(err);
            }
        }
    }

    isReady() {
        return this.ready === true;
    }

    /**
     * Get valid zoom level for map initialization
     * @returns {number} Valid zoom level between 1-20
     */
    getValidZoom() {
        const zoom = this.args.zoom;
        // Validate zoom is a valid number within Leaflet bounds (1-20)
        if (typeof zoom === 'number' && !isNaN(zoom) && zoom >= 1 && zoom <= 20) {
            return zoom;
        }
        // Return default zoom of 14 if invalid
        return 14;
    }

    /**
     * Handles location updates from the location service
     * @param {Object} coordinates - The new coordinates
     */
    #handleLocationUpdate(coordinates) {
        if (coordinates && typeof coordinates.latitude === 'number' && typeof coordinates.longitude === 'number') {
            this.latitude = coordinates.latitude;
            this.longitude = coordinates.longitude;

            // Update map position if map is loaded
            if (this.map && this.map.setView) {
                this.map.setView([coordinates.latitude, coordinates.longitude], this.zoom);
            }
        }
    }

    /**
     * Updates coordinates from location service on initialization
     */
    #updateCoordinatesFromLocation() {
        // Initial coordinates are already set via tracked properties
        // This method ensures we have the latest location service values
        this.latitude = this.location.getLatitude();
        this.longitude = this.location.getLongitude();
    }

    #setMap(map) {
        set(map, 'livemap', this);
        this.map = map;
        this.leafletMapManager.setMap(map);
        this.universe.trigger('fleet-ops.live-map.loaded', map);
        this.universe.set('component:fleet-ops:live-map', this);
    }

    #setResourceLayer(model, layer, options = {}) {
        const { hidden = false } = options;
        const type = getModelName(model);

        set(model, 'leafletLayer', layer);
        set(layer, 'record_id', model.id);
        set(layer, 'record_type', type);

        this.leafletLayerVisibilityManager.registerLayer(pluralize(type), layer, { id: model.id, hidden });
    }

    #createMapContextMenu(map) {
        const items = [
            {
                text: this.intl.t('live-map.show-coordinates'),
                callback: this.leafletMapManager.showCoordinates,
                index: 0,
            },
            {
                text: this.intl.t('live-map.center-map'),
                callback: this.leafletMapManager.centerMap,
                index: 1,
            },
            {
                text: this.intl.t('live-map.zoom-in'),
                callback: this.leafletMapManager.zoomIn,
                index: 2,
            },
            {
                text: this.intl.t('live-map.zoom-out'),
                callback: this.leafletMapManager.zoomOut,
                index: 3,
            },
            {
                text: this.intl.t('live-map.toggle-draw-controls'),
                callback: this.leafletMapManager.toggleDrawControl,
                index: 4,
            },
            { separator: true },
            {
                text: this.intl.t('live-map.create-new-service'),
                callback: () => this.geofence.createServiceArea(),
                index: 5,
            },
            this.serviceAreaActions.serviceAreas.length ? { separator: true } : null,
            ...this.serviceAreaActions.serviceAreas.map((serviceArea, i) => {
                return {
                    text: this.intl.t('live-map.focus-service', { serviceName: serviceArea.name }),
                    callback: () => this.geofence.focusServiceArea(serviceArea),
                    index: 6 + i,
                };
            }),
        ].filter(Boolean);

        const registry = this.leafletContextmenuManager.createContextMenu('map', map, items);
        this.universe.trigger('fleet-ops:contextmenu:map:created', registry, this.leafletContextmenuManager);

        return registry;
    }

    #createZoneContextMenu(zone, layer) {
        let items = [
            {
                separator: true,
            },
            {
                text: this.intl.t('live-map.edit-zone', { zoneName: zone.name }),
                callback: () => this.zoneActions.modal.edit(zone),
            },
            {
                text: this.intl.t('live-map.edit-boundaries', { resource: zone.name }),
                callback: () => this.geofence.editZone(zone),
            },
            {
                text: this.intl.t('live-map.delete-zone', { zoneName: zone.name }),
                callback: () => this.zoneActions.delete(zone),
            },
        ];

        // create contextmenu registry
        const contextmenuRegistry = this.leafletContextmenuManager.createContextMenu(`zone:${zone.public_id}`, layer, items, { zone });
        this.universe.trigger('fleet-ops:contextmenu:zone:created', contextmenuRegistry, this.leafletContextmenuManager);

        return contextmenuRegistry;
    }

    #createServiceAreaContextMenu(serviceArea, layer) {
        let items = [
            {
                separator: true,
            },
            {
                text: this.intl.t('live-map.blur-service', { serviceName: serviceArea.name }),
                callback: () => this.geofence.blurServiceArea(serviceArea),
            },
            {
                text: this.intl.t('live-map.create-zone', { serviceName: serviceArea.name }),
                callback: () => this.geofence.createZone(serviceArea),
            },
            {
                text: this.intl.t('live-map.edit-service', { serviceName: serviceArea.name }),
                callback: () => this.serviceAreaActions.modal.edit(serviceArea),
            },
            {
                text: this.intl.t('live-map.edit-boundaries', { resource: serviceArea.name }),
                callback: () => this.geofence.editServiceArea(serviceArea),
            },
            {
                text: this.intl.t('live-map.delete-service', { serviceName: serviceArea.name }),
                callback: () => this.serviceAreaActions.delete(serviceArea),
            },
        ];

        // create contextmenu registry
        const contextmenuRegistry = this.leafletContextmenuManager.createContextMenu(`service-area:${serviceArea.public_id}`, layer, items, { serviceArea });
        this.universe.trigger('fleet-ops:contextmenu:service-area:created', contextmenuRegistry, this.leafletContextmenuManager);

        return contextmenuRegistry;
    }

    #createDriverContextMenu(driver, layer) {
        let items = [
            {
                separator: true,
            },
            {
                text: this.intl.t('live-map.view-driver', { driverName: driver.name }),
                callback: () => this.driverActions.panel.view(driver),
            },
            {
                text: this.intl.t('live-map.edit-driver', { driverName: driver.name }),
                callback: () => this.driverActions.panel.edit(driver, { useDefaultSaveTask: true }),
            },
            {
                text: this.intl.t('live-map.delete-driver', { driverName: driver.name }),
                callback: () => this.driverActions.delete(driver),
            },
            {
                text: this.intl.t('live-map.view-vehicle-for', { driverName: driver.name }),
                callback: () => this.vehicleActions.panel.view(driver.vehicle),
            },
        ];

        // append items from universe registry
        const registeredContextMenuItems = this.menuService.getMenuItems('fleet-ops:contextmenu:driver');
        if (isArray(registeredContextMenuItems)) {
            items = [
                ...items,
                ...registeredContextMenuItems.map((menuItem) => {
                    return {
                        text: menuItem.title,
                        callback: () => {
                            const callbackContext = {
                                driver,
                                layer,
                                contextmenuService: this.leafletContextmenuManager,
                                menuItem,
                            };
                            return menuItem.onClick(callbackContext);
                        },
                    };
                }),
            ];
        }

        // create contextmenu registry
        const contextmenuRegistry = this.leafletContextmenuManager.createContextMenu(`driver:${driver.public_id}`, layer, items, { driver });
        this.universe.trigger('fleet-ops:contextmenu:driver:created', contextmenuRegistry, this.leafletContextmenuManager);

        return contextmenuRegistry;
    }

    #createVehicleContextMenu(vehicle, layer) {
        let items = [
            {
                separator: true,
            },
            {
                text: this.intl.t('live-map.view-vehicle', { vehicleName: vehicle.displayName }),
                callback: () => this.vehicleActions.panel.view(vehicle),
            },
            {
                text: this.intl.t('live-map.edit-vehicle', { vehicleName: vehicle.displayName }),
                callback: () => this.vehicleActions.panel.edit(vehicle, { useDefaultSaveTask: true }),
            },
            {
                text: this.intl.t('live-map.delete-vehicle', { vehicleName: vehicle.displayName }),
                callback: () => this.vehicleActions.delete(vehicle),
            },
        ];

        // append items from universe registry
        const registeredContextMenuItems = this.menuService.getMenuItems('fleet-ops:contextmenu:vehicle');
        if (isArray(registeredContextMenuItems)) {
            items = [
                ...items,
                ...registeredContextMenuItems.map((menuItem) => {
                    return {
                        text: menuItem.title,
                        callback: () => {
                            const callbackContext = {
                                vehicle,
                                layer,
                                contextmenuService: this.leafletContextmenuManager,
                                menuItem,
                            };
                            return menuItem.onClick(callbackContext);
                        },
                    };
                }),
            ];
        }

        // create contextmenu registry
        const contextmenuRegistry = this.leafletContextmenuManager.createContextMenu(`vehicle:${vehicle.public_id}`, layer, items, { vehicle });
        this.universe.trigger('fleet-ops:contextmenu:vehicle:created', contextmenuRegistry, this.leafletContextmenuManager);

        return contextmenuRegistry;
    }

    /**
     * Safely gets a valid latitude value with fallback to default
     * @returns {number} Valid latitude value
     */
    #getValidLatitude() {
        const lat = this.location.getLatitude();

        // Validate latitude is a number and within valid range (-90 to 90)
        if (typeof lat === 'number' && !isNaN(lat) && lat >= -90 && lat <= 90) {
            return lat;
        }

        // Fallback to default Singapore latitude
        return 1.369;
    }

    /**
     * Safely gets a valid longitude value with fallback to default
     * @returns {number} Valid longitude value
     */
    #getValidLongitude() {
        const lng = this.location.getLongitude();

        // Validate longitude is a number and within valid range (-180 to 180)
        if (typeof lng === 'number' && !isNaN(lng) && lng >= -180 && lng <= 180) {
            return lng;
        }

        // Fallback to default Singapore longitude
        return 103.8864;
    }

    /**
     * Render color-coded, offset polylines on the live map for all active routes.
     *
     * Each route is assigned a deterministic color from the palette (derived from
     * the order public_id) and drawn as a two-layer cased polyline. When multiple
     * routes share the same road segments, alternating pixel offsets are applied
     * so that each route remains visually distinct.
     *
     * Route geometry is sourced from `route.details.coordinates` (OSRM format) or
     * `route.details.geometry.coordinates` (GeoJSON LineString format).
     *
     * @param {Array} routes - Array of route model objects from the live API
     */
    #renderLiveRoutes(routes) {
        if (!this.map || !isArray(routes) || routes.length === 0) return;

        // Clear any previously rendered route layers before re-rendering
        this.#clearLiveRouteLayerGroups();

        // Pixel offsets cycle through to visually separate overlapping routes.
        // Values are in CSS pixels; alternating left/right keeps routes balanced.
        const PIXEL_OFFSETS = [0, -4, 4, -8, 8, -12, 12];

        routes.forEach((route, index) => {
            // Derive a stable color from the order public_id
            const orderId = route.get ? route.get('order.public_id') || route.get('public_id') : route.order_public_id || route.public_id || String(index);
            const status = route.get ? route.get('order.status') || route.get('status') : route.order_status || route.status || 'dispatched';
            const routeColor = colorForId(orderId);
            const lineStyles = routeStyleForStatus(status, routeColor);

            // Extract geometry coordinates from the route details JSON
            const coordinates = this.#extractRouteCoordinates(route);
            if (!coordinates || coordinates.length < 2) return;

            // Convert [lng, lat] pairs (OSRM/GeoJSON) to Leaflet [lat, lng] pairs
            const latLngs = coordinates.map(([lng, lat]) => [lat, lng]);

            // Apply a pixel offset to visually separate overlapping routes.
            // We use a CSS transform on the SVG path via a custom pane approach,
            // or simply shift by varying the weight for a layered visual effect.
            const pixelOffset = PIXEL_OFFSETS[index % PIXEL_OFFSETS.length];

            const group = L.layerGroup().addTo(this.map);

            // Build the tooltip content for this route
            const driverName = route.get ? route.get('driver_assigned.name') || route.get('driver_name') : route.driver_name || 'Unassigned';
            const orderPublicId = route.get ? route.get('order.public_id') || route.get('public_id') : route.order_public_id || route.public_id || '—';
            const tooltipContent = `<div class="fleetops-route-tooltip">
                <div class="fleetops-route-tooltip__id">${orderPublicId}</div>
                <div class="fleetops-route-tooltip__driver">Driver: ${driverName}</div>
                <div class="fleetops-route-tooltip__status">Status: ${status}</div>
            </div>`;

            // Draw each style layer (casing + main line) as separate polylines
            lineStyles.forEach((styleOptions, styleIndex) => {
                // For overlapping routes, nudge weight slightly per offset index
                // so routes at the same pixel still show through each other
                const adjustedOptions = {
                    ...styleOptions,
                    weight: (styleOptions.weight || 5) + pixelOffset * 0.15,
                };

                const polyline = L.polyline(latLngs, adjustedOptions);

                // Only bind the interactive tooltip to the topmost (last) style layer
                if (styleIndex === lineStyles.length - 1) {
                    polyline.bindTooltip(tooltipContent, {
                        sticky: true,
                        className: 'fleetops-route-tooltip-wrapper',
                    });
                }

                polyline.addTo(group);
            });

            // Store the group so we can remove it on refresh or destroy
            const routeKey = route.id || orderId || String(index);
            this._liveRouteLayerGroups.set(routeKey, group);
        });
    }

    /**
     * Extract an array of [lng, lat] coordinate pairs from a route model's
     * `details` JSON field. Handles both OSRM and GeoJSON LineString formats.
     *
     * @param {Object} route - Route model
     * @returns {Array|null} Array of [lng, lat] pairs, or null if not available
     */
    #extractRouteCoordinates(route) {
        let details;
        try {
            details = route.get ? route.get('details') : route.details;
            if (typeof details === 'string') {
                details = JSON.parse(details);
            }
        } catch (_) {
            return null;
        }

        if (!details) return null;

        // OSRM table format: details.coordinates = [[lng, lat], ...]
        if (isArray(details.coordinates) && details.coordinates.length >= 2) {
            return details.coordinates;
        }

        // GeoJSON LineString format: details.geometry.coordinates = [[lng, lat], ...]
        if (details.geometry && isArray(details.geometry.coordinates) && details.geometry.coordinates.length >= 2) {
            return details.geometry.coordinates;
        }

        // OSRM route response format: details.routes[0].geometry.coordinates
        if (isArray(details.routes) && details.routes.length > 0) {
            const firstRoute = details.routes[0];
            if (firstRoute.geometry && isArray(firstRoute.geometry.coordinates)) {
                return firstRoute.geometry.coordinates;
            }
        }

        return null;
    }

    /**
     * Remove all live route polyline LayerGroups from the map and clear the registry.
     */
    #clearLiveRouteLayerGroups() {
        this._liveRouteLayerGroups.forEach((group) => {
            try {
                if (this.map) {
                    this.map.removeLayer(group);
                } else {
                    group.remove();
                }
            } catch (_) {}
        });
        this._liveRouteLayerGroups.clear();
    }

    #changeTileSource(source) {
        switch (source) {
            case 'dark':
                this.theme = 'dark';
                this.tileUrl = 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png';
                break;
            case 'custom':
                this.theme = 'custom';
                this.tileUrl = source.startsWith('https://') ? source : 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png';
                break;
            case 'light':
            default:
                this.theme = 'light';
                this.tileUrl = 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png';
                break;
        }
    }
}
