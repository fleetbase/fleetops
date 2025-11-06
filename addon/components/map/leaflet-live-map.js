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
    }

    @action didLoad({ target: map }) {
        this.#setMap(map);
        this.#createMapContextMenu(map);
        this.trigger('onLoad', ...arguments);
        this.load.perform();
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
            const data = yield all([
                this.loadResource.perform('routes'),
                this.loadResource.perform('vehicles'),
                this.loadResource.perform('drivers'),
                this.loadResource.perform('places'),
                this.loadResource.perform('service-areas'),
            ]);

            this.#createMapContextMenu(this.map);
            this.trigger('onLoaded', { map: this.map, data });
            this.ready = true;
        } catch (err) {
            debug('Failed to load live map: ' + err.message);
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
        this.universe.createRegistryEvent('fleet-ops:contextmenu:map', 'created', registry, this.leafletContextmenuManager);

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
        this.universe.createRegistryEvent('fleet-ops:contextmenu:zone', 'created', contextmenuRegistry, this.leafletContextmenuManager);

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
        this.universe.createRegistryEvent('fleet-ops:contextmenu:service-area', 'created', contextmenuRegistry, this.leafletContextmenuManager);

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
        const registeredContextMenuItems = this.universe.getMenuItemsFromRegistry('fleet-ops:contextmenu:driver');
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
        this.universe.createRegistryEvent('fleet-ops:contextmenu:driver', 'created', contextmenuRegistry, this.leafletContextmenuManager);

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
        const registeredContextMenuItems = this.universe.getMenuItemsFromRegistry('fleet-ops:contextmenu:vehicle');
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
        this.universe.createRegistryEvent('fleet-ops:contextmenu:vehicle', 'created', contextmenuRegistry, this.leafletContextmenuManager);

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
