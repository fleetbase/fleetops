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
import { next } from '@ember/runloop';
import getModelName from '@fleetbase/ember-core/utils/get-model-name';

export default class MapLeafletLiveMapComponent extends Component {
    @service mapManager;
    @service mapSettings;
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
    @service geofenceEventBus;
    @service currentUser;

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
    _viewportReloadLocks = new Set();

    constructor() {
        super(...arguments);

        // Store bound function reference for proper cleanup
        this._locationUpdateHandler = this.#handleLocationUpdate.bind(this);

        // Listen for location updates from the location service
        this.universe.on('user.located', this._locationUpdateHandler);

        // Ensure we have valid coordinates on initialization
        this.#updateCoordinatesFromLocation();

        if (this.currentUser.companyId) {
            next(this, () => this.geofenceEventBus.subscribe(this.currentUser.companyId));
        } else {
            this._currentUserLoadedHandler = () => next(this, () => this.geofenceEventBus.subscribe(this.currentUser.companyId));
            this.currentUser.on('user.loaded', this._currentUserLoadedHandler);
        }

        // Subscribe to geofence events so the live map can react to boundary crossings
        this._geofenceEnteredHandler = this.#handleGeofenceEntered.bind(this);
        this._geofenceExitedHandler = this.#handleGeofenceExited.bind(this);
        this.universe.on('fleet-ops.geofence.entered', this._geofenceEnteredHandler);
        this.universe.on('fleet-ops.geofence.exited', this._geofenceExitedHandler);
    }

    willDestroy() {
        super.willDestroy();

        // Clean up event listener using stored reference
        if (this._locationUpdateHandler) {
            this.universe.off('user.located', this._locationUpdateHandler);
            this._locationUpdateHandler = null;
        }
        if (this._currentUserLoadedHandler) {
            this.currentUser.off('user.loaded', this._currentUserLoadedHandler);
            this._currentUserLoadedHandler = null;
        }
        if (this._geofenceEnteredHandler) {
            this.universe.off('fleet-ops.geofence.entered', this._geofenceEnteredHandler);
            this._geofenceEnteredHandler = null;
        }
        if (this._geofenceExitedHandler) {
            this.universe.off('fleet-ops.geofence.exited', this._geofenceExitedHandler);
            this._geofenceExitedHandler = null;
        }
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

    /**
     * Called when the Google Maps adapter is ready.
     * Mirrors `didLoad` but receives the adapter instance instead of a raw Leaflet map.
     *
     * @param {{ target: * }} event
     */
    @action didLoadGoogleMap({ target: map }) {
        this.#setMapFromAdapter(map);
        this.trigger('onLoad', { target: map });
        this.load.perform();
    }

    suspendViewportReload(lockId = 'default') {
        this._viewportReloadLocks.add(lockId);
    }

    resumeViewportReload(lockId = 'default') {
        this._viewportReloadLocks.delete(lockId);
    }

    get isViewportReloadSuspended() {
        return this._viewportReloadLocks.size > 0;
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
        this.mapManager.adapter?.setDrawControl?.(drawControl, this.leafletMapManager.drawControlFeatureGroup);
        this.trigger('onDrawControlCreated', ...arguments);
    }

    @action didCreateDrawControlFeatureGroup(featureGroup) {
        this.leafletMapManager.setDrawControlFeatureGroup(featureGroup);
        this.mapManager.adapter?.setDrawControl?.(this.leafletMapManager.drawControl, featureGroup);
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
                this.mapManager.once?.('moveend', () => this.mapManager.panBy?.(200, 0)) ?? this.map?.once?.('moveend', () => this.map?.panBy?.([200, 0]));
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
                this.mapManager.once?.('moveend', () => this.mapManager.panBy?.(200, 0)) ?? this.map?.once?.('moveend', () => this.map?.panBy?.([200, 0]));
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
                this.mapManager.once?.('moveend', () => this.mapManager.panBy?.(200, 0)) ?? this.map?.once?.('moveend', () => this.map?.panBy?.([200, 0]));
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

    @action rebuildMapContextMenu() {
        if (!this.map) {
            return;
        }

        this.#createMapContextMenu(this.map);
        this.#refreshResourceContextMenus();
        this.#pruneResourceContextMenus();
    }

    @action syncServiceAreaContextMenus() {
        this.rebuildMapContextMenu();
    }

    /** load resources and wait for stuff here and trigger map ready **/
    @task *load() {
        try {
            // Get initial map bounds for spatial filtering (provider-agnostic)
            const mapBounds = this.mapManager.getBounds?.() ?? (this.map ? this.map.getBounds() : null);
            const bounds = this.#serializeBoundsForRequest(mapBounds);
            const params = bounds ? { bounds } : {};

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
        } catch (err) {
            debug('Failed to load live map: ' + err.message);
        }
    }

    @task({ restartable: true }) *reloadResourcesInViewport() {
        if (!this.map && !this.mapManager.isReady) {
            return;
        }
        if (this.isViewportReloadSuspended) {
            return;
        }
        // Get current map bounds (provider-agnostic)
        const rawBounds = this.mapManager.getBounds?.() ?? this.map?.getBounds();
        if (!rawBounds) return;
        const bounds = this.#serializeBoundsForRequest(rawBounds);
        if (!bounds) return;
        const params = { bounds };

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

    get shouldUseGoogleMaps() {
        return this.mapSettings.isGoogleMaps;
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
     * Handles a geofence.entered event from the GeofenceEventBus.
     * Briefly highlights the geofence layer on the map to provide visual feedback.
     *
     * @param {Object} event - Normalised geofence event object
     */
    #handleGeofenceEntered(event) {
        debug(`[LiveMap] geofence.entered — driver: ${event.driverName}, geofence: ${event.geofenceName}`);
        this.#flashGeofenceLayer(event.geofenceUuid, '#22c55e'); // green
    }

    /**
     * Handles a geofence.exited event from the GeofenceEventBus.
     * Briefly highlights the geofence layer on the map to provide visual feedback.
     *
     * @param {Object} event - Normalised geofence event object
     */
    #handleGeofenceExited(event) {
        debug(`[LiveMap] geofence.exited — driver: ${event.driverName}, geofence: ${event.geofenceName}`);
        this.#flashGeofenceLayer(event.geofenceUuid, '#ef4444'); // red
    }

    /**
     * Briefly changes the fill colour of a geofence polygon layer on the map
     * to provide visual feedback when a driver enters or exits.
     *
     * @param {string} geofenceUuid - UUID of the zone or service area
     * @param {string} flashColor   - Hex colour to flash
     */
    #flashGeofenceLayer(geofenceUuid, flashColor) {
        if (!geofenceUuid || !this.map) {
            return;
        }

        // Iterate over all Leaflet layers to find the matching geofence polygon
        this.map.eachLayer((layer) => {
            const model = layer._model;
            if (model && model.uuid === geofenceUuid && typeof layer.setStyle === 'function') {
                const originalStyle = {
                    color: layer.options.color,
                    fillColor: layer.options.fillColor,
                    weight: layer.options.weight,
                };

                // Flash to the event colour
                layer.setStyle({ color: flashColor, fillColor: flashColor, weight: 3 });

                // Restore original style after 2 seconds
                setTimeout(() => {
                    if (!layer._map) return; // layer may have been removed
                    layer.setStyle(originalStyle);
                }, 2000);
            }
        });
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
        this.mapManager.setLivemap(this);
        // Also register with the provider-agnostic manager so all services can use it
        this.mapManager.setActiveProvider('leaflet');
        this.mapManager.setMapInstance(map);
        this.universe.trigger('fleet-ops.live-map.loaded', map);
        this.universe.set('component:fleet-ops:live-map', this);
    }

    /**
     * Called when the Google Maps adapter initialises.
     * Registers the adapter as the active provider so all services
     * route through the provider-agnostic MapManagerService.
     *
     * @param {import('../services/map-manager').default} adapter
     */
    #setMapFromAdapter(map) {
        this.map = map;
        this.mapManager.setLivemap(this);
        this.mapManager.setActiveProvider('google');
        this.universe.trigger('fleet-ops.live-map.loaded', map);
        this.universe.set('component:fleet-ops:live-map', this);
    }

    #serializeBoundsForRequest(rawBounds) {
        if (!rawBounds) return null;

        if (isArray(rawBounds) && rawBounds.length === 2) {
            const [[south, west], [north, east]] = rawBounds;
            return [south, west, north, east].every(Number.isFinite) ? [south, west, north, east] : null;
        }

        if (typeof rawBounds.getSouth === 'function') {
            const bounds = [rawBounds.getSouth(), rawBounds.getWest(), rawBounds.getNorth(), rawBounds.getEast()];
            return bounds.every(Number.isFinite) ? bounds : null;
        }

        if (typeof rawBounds.getSouthWest === 'function') {
            const sw = rawBounds.getSouthWest();
            const ne = rawBounds.getNorthEast();
            const bounds = [sw.lat(), sw.lng(), ne.lat(), ne.lng()];
            return bounds.every(Number.isFinite) ? bounds : null;
        }

        return null;
    }

    #setResourceLayer(model, layer, options = {}) {
        const { hidden = false } = options;
        const type = getModelName(model);
        // Leaflet backward-compat: keep leafletLayer for existing code
        set(model, 'leafletLayer', layer);
        set(layer, 'record_id', model.id);
        set(layer, 'record_type', type);
        this.leafletLayerVisibilityManager.registerLayer(pluralize(type), layer, { id: model.id, hidden });

        if (type === 'service-area' || type === 'zone') {
            this.mapManager.registerPolygon?.(model.id, layer, { type, hidden });
        } else {
            this.mapManager.registerMarker?.(model.id, layer, { type, hidden });
        }
    }

    #createMapContextMenu(map) {
        const serviceAreas = Array.from(this.serviceAreaActions.serviceAreas ?? []);
        const items = [
            {
                text: this.intl.t('live-map.show-coordinates'),
                callback: this.mapManager.showCoordinates?.bind(this.mapManager) ?? this.leafletMapManager.showCoordinates,
                index: 0,
            },
            {
                text: this.intl.t('live-map.center-map'),
                callback: this.mapManager.centerMap?.bind(this.mapManager) ?? this.leafletMapManager.centerMap,
                index: 1,
            },
            {
                text: this.intl.t('live-map.zoom-in'),
                callback: this.mapManager.zoomIn?.bind(this.mapManager) ?? this.leafletMapManager.zoomIn,
                index: 2,
            },
            {
                text: this.intl.t('live-map.zoom-out'),
                callback: this.mapManager.zoomOut?.bind(this.mapManager) ?? this.leafletMapManager.zoomOut,
                index: 3,
            },
            {
                text: this.intl.t('live-map.toggle-draw-controls'),
                callback: this.geofence.toggleDrawControl.bind(this.geofence),
                index: 4,
            },
            { separator: true },
            {
                text: this.intl.t('live-map.create-new-service'),
                callback: () => this.geofence.createServiceArea(),
                index: 5,
            },
            serviceAreas.length ? { separator: true } : null,
            ...serviceAreas.map((serviceArea, i) => {
                return {
                    text: this.intl.t('live-map.focus-service', { serviceName: this.#resourceDisplayName(serviceArea) }),
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
        const zoneName = this.#resourceDisplayName(zone);
        let items = [
            {
                separator: true,
            },
            {
                text: this.intl.t('live-map.edit-zone', { zoneName }),
                callback: () => this.zoneActions.modal.edit(zone),
            },
            {
                text: this.intl.t('live-map.delete-zone', { zoneName }),
                callback: () => this.#deleteZone(zone),
            },
        ];

        if (!this.mapManager.isGoogleMaps) {
            items.splice(2, 0, {
                text: this.intl.t('live-map.edit-boundaries', { resource: zoneName }),
                callback: () => this.geofence.editZone(zone),
            });
        }

        // create contextmenu registry
        const contextmenuRegistry = this.leafletContextmenuManager.createContextMenu(this.#resourceContextKey('zone', zone), layer, items, { zone });
        this.universe.trigger('fleet-ops:contextmenu:zone:created', contextmenuRegistry, this.leafletContextmenuManager);

        return contextmenuRegistry;
    }

    #createServiceAreaContextMenu(serviceArea, layer) {
        const serviceName = this.#resourceDisplayName(serviceArea);
        let items = [
            {
                separator: true,
            },
            {
                text: this.intl.t('live-map.blur-service', { serviceName }),
                callback: () => this.geofence.blurServiceArea(serviceArea),
            },
            {
                text: this.intl.t('live-map.create-zone', { serviceName }),
                callback: () => this.geofence.createZone(serviceArea),
            },
            {
                text: this.intl.t('live-map.edit-service', { serviceName }),
                callback: () => this.serviceAreaActions.modal.edit(serviceArea),
            },
            {
                text: this.intl.t('live-map.delete-service', { serviceName }),
                callback: () => this.#deleteServiceArea(serviceArea),
            },
        ];

        if (!this.mapManager.isGoogleMaps) {
            items.splice(4, 0, {
                text: this.intl.t('live-map.edit-boundaries', { resource: serviceName }),
                callback: () => this.geofence.editServiceArea(serviceArea),
            });
        }

        // create contextmenu registry
        const contextmenuRegistry = this.leafletContextmenuManager.createContextMenu(this.#resourceContextKey('service-area', serviceArea), layer, items, { serviceArea });
        this.universe.trigger('fleet-ops:contextmenu:service-area:created', contextmenuRegistry, this.leafletContextmenuManager);

        return contextmenuRegistry;
    }

    #resourceContextKey(type, resource) {
        return `${type}:${resource?.public_id ?? resource?.id}`;
    }

    #resourceDisplayName(resource) {
        return resource?.get?.('name') || resource?.name || resource?.display_name || resource?.public_id || resource?.id || '';
    }

    #deleteServiceArea(serviceArea) {
        const zones = Array.from(serviceArea?.zones ?? []);

        return this.serviceAreaActions.delete(
            serviceArea,
            {},
            {
                callback: () => {
                    this.#removeServiceAreaFromMapState(serviceArea, zones);
                    this.rebuildMapContextMenu();
                },
            }
        );
    }

    #deleteZone(zone) {
        return this.zoneActions.delete(
            zone,
            {},
            {
                callback: () => {
                    this.#removeZoneFromMapState(zone);
                    this.rebuildMapContextMenu();
                },
            }
        );
    }

    #removeServiceAreaFromMapState(serviceArea, zones = []) {
        const serviceAreaId = serviceArea?.id;
        const serviceAreaKey = this.#resourceContextKey('service-area', serviceArea);

        zones.forEach((zone) => this.#removeZoneFromMapState(zone));
        this.#removeOverlay(serviceArea);
        this.leafletContextmenuManager.removeContextMenu(serviceAreaKey);

        this.serviceAreaActions.serviceAreas = Array.from(this.serviceAreaActions.serviceAreas ?? []).filter((candidate) => candidate?.id !== serviceAreaId);
    }

    #removeZoneFromMapState(zone) {
        const zoneId = zone?.id;
        const zoneKey = this.#resourceContextKey('zone', zone);

        this.#removeOverlay(zone);
        this.leafletContextmenuManager.removeContextMenu(zoneKey);

        for (const serviceArea of Array.from(this.serviceAreaActions.serviceAreas ?? [])) {
            const zones = serviceArea?.zones;
            if (!zones) {
                continue;
            }

            const currentZones = Array.from(zones);
            const nextZones = currentZones.filter((candidate) => candidate?.id !== zoneId);
            if (nextZones.length !== currentZones.length) {
                if (typeof zones.removeObject === 'function') {
                    zones.removeObject(zone);
                } else {
                    serviceArea.set?.('zones', nextZones);
                }
                break;
            }
        }

        this.serviceAreaActions.serviceAreas = Array.from(this.serviceAreaActions.serviceAreas ?? []);
    }

    #removeOverlay(resource) {
        if (!resource?.id) {
            return;
        }

        this.mapManager.removeOverlay?.(resource.id);

        const layer = resource?.leafletLayer;
        if (layer) {
            this.mapManager.removeLayer?.(layer);
        }
    }

    #refreshResourceContextMenus() {
        for (const serviceArea of Array.from(this.serviceAreaActions.serviceAreas ?? [])) {
            const serviceAreaLayer = this.mapManager.getOverlay(serviceArea?.id) ?? serviceArea?.leafletLayer;
            if (serviceAreaLayer) {
                this.#createServiceAreaContextMenu(serviceArea, serviceAreaLayer);
            }

            for (const zone of Array.from(serviceArea?.zones ?? [])) {
                const zoneLayer = this.mapManager.getOverlay(zone?.id) ?? zone?.leafletLayer;
                if (zoneLayer) {
                    this.#createZoneContextMenu(zone, zoneLayer);
                }
            }
        }
    }

    #pruneResourceContextMenus() {
        const activeServiceAreaKeys = new Set();
        const activeZoneKeys = new Set();

        for (const serviceArea of Array.from(this.serviceAreaActions.serviceAreas ?? [])) {
            activeServiceAreaKeys.add(this.#resourceContextKey('service-area', serviceArea));

            for (const zone of Array.from(serviceArea?.zones ?? [])) {
                activeZoneKeys.add(this.#resourceContextKey('zone', zone));
            }
        }

        for (const registry of Object.values(this.leafletContextmenuManager.contextMenuRegistry ?? {})) {
            if (registry?.serviceArea && !activeServiceAreaKeys.has(this.#resourceContextKey('service-area', registry.serviceArea))) {
                this.leafletContextmenuManager.removeContextMenu(this.#resourceContextKey('service-area', registry.serviceArea));
            }

            if (registry?.zone && !activeZoneKeys.has(this.#resourceContextKey('zone', registry.zone))) {
                this.leafletContextmenuManager.removeContextMenu(this.#resourceContextKey('zone', registry.zone));
            }
        }
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
