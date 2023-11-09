import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, set } from '@ember/object';
import { isArray } from '@ember/array';
import { dasherize, camelize, classify } from '@ember/string';
import { singularize } from 'ember-inflector';
import { alias } from '@ember/object/computed';
import { later } from '@ember/runloop';
import { allSettled } from 'rsvp';
import getWithDefault from '@fleetbase/ember-core/utils/get-with-default';

const DEFAULT_LATITUDE = 1.369;
const DEFAULT_LONGITUDE = 103.8864;

/**
 * Component which displays live activity.
 *
 * @class
 */
export default class LiveMapComponent extends Component {
    /**
     * Inject the `store` service.
     *
     * @memberof LiveMapComponent
     */
    @service store;

    /**
     * Inject the `fetch` service.
     *
     * @memberof LiveMapComponent
     */
    @service fetch;

    /**
     * Inject the `socket` service.
     *
     * @memberof LiveMapComponent
     */
    @service socket;

    /**
     * Inject the `currentUser` service.
     *
     * @memberof LiveMapComponent
     */
    @service currentUser;

    /**
     * Inject the `notifications` service.
     *
     * @memberof LiveMapComponent
     */
    @service notifications;

    /**
     * Inject the `serviceAreas` service.
     *
     * @memberof LiveMapComponent
     */
    @service serviceAreas;

    /**
     * Inject the `appCache` service.
     *
     * @memberof LiveMapComponent
     */
    @service appCache;

    /**
     * Inject the `universe` service.
     *
     * @memberof LiveMapComponent
     */
    @service universe;

    /**
     * Inject the `crud` service.
     *
     * @memberof LiveMapComponent
     */
    @service crud;

    /**
     * Inject the `contextPanel` service.
     *
     * @memberof LiveMapComponent
     */
    @service contextPanel;

    /**
     * Inject the `leafletMapManager` service.
     *
     * @memberof LiveMapComponent
     */
    @service leafletMapManager;

    /**
     * Inject the `leafletContextmenuManager` service.
     *
     * @memberof LiveMapComponent
     */
    @service leafletContextmenuManager;

    /**
     * An array of routes.
     * @type {Array}
     * @memberof LiveMapComponent
     */
    @tracked routes = [];

    /**
     * An array of drivers.
     * @type {Array}
     * @memberof LiveMapComponent
     */
    @tracked drivers = [];

    /**
     * An array of vehicles.
     * @type {Array}
     * @memberof LiveMapComponent
     */
    @tracked vehicles = [];

    /**
     * An array of places.
     * @type {Array}
     * @memberof LiveMapComponent
     */
    @tracked places = [];

    /**
     * An array of channels.
     * @type {Array}
     * @memberof LiveMapComponent
     */
    @tracked channels = [];

    /**
     * Indicates if data is loading.
     * @type {boolean}
     * @memberof LiveMapComponent
     */
    @tracked isLoading = true;

    /**
     * Indicates if the component is ready.
     * @type {boolean}
     * @memberof LiveMapComponent
     */
    @tracked isReady = false;

    /**
     * Indicates if all the data requested has completed loading.
     * @type {boolean}
     * @memberof LiveMapComponent
     */
    @tracked isDataLoaded = false;

    /**
     * Controls for visibility.
     * @type {Object}
     * @memberof LiveMapComponent
     */
    @tracked visibilityControls = {
        vehicles: true,
        onlineVehicles: true,
        offlineVehicles: true,
        drivers: true,
        onlineDrivers: true,
        offlineDrivers: true,
        places: true,
    };

    /**
     * An array of active service areas.
     * @type {Array}
     * @memberof LiveMapComponent
     */
    @tracked activeServiceAreas = [];

    /**
     * An array of editable map layers.
     * @type {Array}
     * @memberof LiveMapComponent
     */
    @tracked editableLayers = [];

    /**
     * The Leaflet map instance.
     * @type {Object}
     * @memberof LiveMapComponent
     */
    @tracked leafletMap;

    /**
     * The Drawer component context API.
     * @type {Object}
     * @memberof LiveMapComponent
     */
    @tracked drawer;

    /**
     * The map's zoom level.
     * @type {number}
     * @memberof LiveMapComponent
     */
    @tracked zoom = 12;

    /**
     * The feature group for drawing on the map.
     * @type {Object}
     * @memberof LiveMapComponent
     */
    @tracked drawFeatureGroup;

    /**
     * The draw control for the map.
     * @type {Object}
     * @memberof LiveMapComponent
     */
    @tracked drawControl;

    /**
     * The URL for the map's tile source.
     * @type {string}
     * @memberof LiveMapComponent
     */
    @tracked tileSourceUrl = 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png';

    /**
     * The latitude for the map view.
     * @type {number}
     * @memberof LiveMapComponent
     */
    @tracked latitude = DEFAULT_LATITUDE;

    /**
     * The longitude for the map view.
     * @type {number}
     * @memberof LiveMapComponent
     */
    @tracked longitude = DEFAULT_LONGITUDE;

    /**
     * Indicates if coordinate setting should be skipped.
     * @type {boolean}
     * @memberof LiveMapComponent
     */
    @tracked skipSetCoordinates = false;

    /**
     * Cache for storing original state of resource arrays.
     * @type {Object.<string, Array>}
     * @memberof LiveMapComponent
     */
    originalResources = {};

    /**
     * The user's latitude from the currentUser.
     * @type {number}
     * @memberof LiveMapComponent
     */
    @alias('currentUser.latitude') userLatitude;

    /**
     * The user's longitude from the currentUser.
     * @type {number}
     * @memberof LiveMapComponent
     */
    @alias('currentUser.longitude') userLongitude;

    /**
     * Creates an instance of LiveMapComponent.
     * @memberof LiveMapComponent
     */
    constructor() {
        super(...arguments);
        this.skipSetCoordinates = getWithDefault(this.args, 'skipSetCoordinates', false);
        this.zoom = getWithDefault(this.args, 'zoom', 12);
        this.tileSourceUrl = getWithDefault(this.args, 'tileSourceUrl', 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png');

        if (this.args.darkMode === true) {
            this.tileSourceUrl = 'https://{s}.tile.jawg.io/jawg-matrix/{z}/{x}/{y}{r}.png?access-token=';
        }
    }

    /**
     * Initializes the LiveMapComponent by triggering events, setting initial coordinates,
     * and loading required live data.
     *
     * @memberof LiveMapComponent
     * @action
     * @function
     */
    @action setupComponent() {
        // trigger that initial coordinates have been set
        this.universe.trigger('fleetops.livemap.loaded', this);

        // set initial coordinates
        this.setInitialCoordinates();

        // load data and complete setup
        this.completeSetup([
            this.fetchLiveData('routes'),
            this.fetchLiveData('vehicles', {
                onLoaded: (vehicles) => {
                    this.watchMovingObjects('vehicles', vehicles);
                },
            }),
            this.fetchLiveData('drivers', {
                onLoaded: (drivers) => {
                    this.watchMovingObjects('drivers', drivers);
                },
            }),
            this.fetchLiveData('places'),
        ]);
    }

    /**
     * Completes the setup of the component by processing an array of live data promises.
     * It waits for all the provided promises to settle and then sets a flag indicating
     * that data fetching is complete. It ensures that any final listening and readiness
     * processes are invoked at the end of the setup process.
     *
     * @param {Promise[]} liveDataPromises - An array of promises that fetch live data.
     * @returns {Promise} A promise that resolves when all data-fetching promises have settled.
     */
    completeSetup(liveDataPromises) {
        return allSettled(liveDataPromises)
            .then(() => {
                this.isDataLoaded = true;
            })
            .finally(() => {
                this.listen();
                this.ready();
            });
    }

    /**
     * Reloads the live map data.
     *
     * @memberof LiveMapComponent
     */
    reload() {
        this.completeSetup([
            this.fetchLiveData('routes'),
            this.fetchLiveData('vehicles', {
                onLoaded: (vehicles) => {
                    this.watchMovingObjects('vehicles', vehicles);
                },
            }),
            this.fetchLiveData('drivers', {
                onLoaded: (drivers) => {
                    this.watchMovingObjects('drivers', drivers);
                },
            }),
            this.fetchLiveData('places'),
        ]);
    }

    /**
     * Marks the LiveMapComponent as ready by setting the "isReady" property and triggering
     * the "onReady" action and a "fleetops.livemap.ready" event.
     *
     * @memberof LiveMapComponent
     * @function
     */
    ready() {
        this.isReady = true;
        this.triggerAction('onReady');
        this.universe.trigger('fleetops.livemap.ready', this);
    }

    /**
     * Sets the initial coordinates for the LiveMapComponent.
     *
     * This function checks if initial coordinates are available in the appCache, and if not,
     * it fetches the coordinates using the "getInitialCoordinates" function. It sets the
     * latitude and longitude properties and triggers an event to notify that coordinates
     * have been set.
     *
     * @memberof LiveMapComponent
     * @async
     * @function
     * @returns {Promise<[number, number] | null>} An array containing the latitude and longitude
     * if available, or null if the function is skipped.
     */
    async setInitialCoordinates() {
        if (this.skipSetCoordinates === false) {
            if (this.appCache.has(['map_latitude', 'map_longitude'])) {
                this.latitude = this.appCache.get('map_latitude');
                this.longitude = this.appCache.get('map_longitude');
                this.isReady = true;

                // trigger that initial coordinates is set to livemap component
                this.universe.trigger('fleetops.livemap.has_coordinates', { latitude: this.latitude, longitude: this.longitude });

                return [this.latitude, this.longitude];
            }

            const { latitude, longitude } = await this.getInitialCoordinates();

            if (this.appCache.doesntHave(['map_latitude', 'map_longitude'])) {
                this.appCache.set('map_latitude', latitude);
                this.appCache.set('map_longitude', longitude);
            }

            this.latitude = latitude;
            this.longitude = longitude;
            this.isReady = true;

            // trigger that initial coordinates is set to livemap component
            this.universe.trigger('fleetops.livemap.has_coordinates', { latitude: this.latitude, longitude: this.longitude });

            return [this.latitude, this.longitude];
        }

        return null;
    }

    /**
     * Sets up the LiveMap component and the Leaflet map instance.
     *
     * This function initializes the LiveMap component, associates it with the Leaflet map instance,
     * triggers the "fleetops.livemap.leaflet_ready" event, and performs additional setup tasks like
     * configuring context menus, hiding draw controls, and associating the map with the "serviceAreas"
     * service. It also triggers the "onLoad" action with the provided event and target.
     *
     * @action
     * @function
     * @param {Event} event - The event object.
     */
    @action setupMap(event) {
        const { target } = event;

        // set liveMapComponent component to instance
        set(target, 'liveMap', this);

        // set map instance
        this.leafletMap = target;

        // trigger liveMap ready through universe
        this.universe.trigger('fleetops.livemap.leaflet_ready', event, target);

        // make fleetops map globally available on the window
        window.FleetOpsLeafletMap = target;

        // store this component to universe
        this.universe.set('FleetOpsLiveMap', this);

        // setup context menu
        this.createMapContextMenu(target);

        // hide draw controls by default
        this.hideDrawControls();

        // set instance to service areas service
        this.serviceAreas.setMapInstance(target);

        // trigger map loaded event
        this.triggerAction('onLoad', ...arguments);
    }

    /**
     * Invokes an action by name on the current component and its arguments (if defined).
     *
     * This function checks if an action with the specified name exists on the current component.
     * If found, it invokes the action with the provided parameters. It also checks the component's
     * arguments for the action and invokes it if defined.
     *
     * @action
     * @function
     * @param {string} actionName - The name of the action to trigger.
     * @param {...any} params - Optional parameters to pass to the action.
     */
    @action triggerAction(actionName, ...params) {
        if (typeof this[actionName] === 'function') {
            this[actionName](...params);
        }

        if (typeof this.args[actionName] === 'function') {
            this.args[actionName](...params);
        }
    }

    /**
     * Fetches live data from the specified path and updates the component state accordingly.
     *
     * @memberof LiveMapComponent
     * @function
     * @param {string} path - The path to fetch live data from.
     * @param {Object} [options={}] - Optional configuration options.
     * @param {Object} [options.params={}] - Additional parameters to include in the request.
     * @param {Function} [options.onLoaded] - A callback function to execute when the data is loaded.
     * @returns {Promise} A promise that resolves with the fetched data.
     */
    fetchLiveData(path, options = {}) {
        this.isLoading = true;

        const internalName = camelize(path);
        const callbackFnName = `on${internalName}Loaded`;
        const params = getWithDefault(options, 'params', {});

        return this.fetch
            .get(`fleet-ops/live/${path}`, params, { normalizeToEmberData: true, normalizeModelType: singularize(internalName) })
            .then((data) => {
                this.triggerAction(callbackFnName);
                this.createVisibilityControl(internalName);
                this[internalName] = data;
                // cache as resource
                this.cacheOriginalResources(internalName);

                if (typeof options.onLoaded === 'function') {
                    options.onLoaded(data);
                }

                return data;
            })
            .catch((error) => {
                if (typeof options.onFailure === 'function') {
                    options.onFailure(error);
                }
            })
            .finally(() => {
                this.isLoading = false;
            });
    }

    /**
     * Creates or updates a visibility control for a specific element by name.
     *
     * @function
     * @param {string} name - The name or identifier for the visibility control.
     * @param {boolean} [visible=true] - A boolean value indicating whether the element is initially visible (default is true).
     */
    createVisibilityControl(name, visible = true) {
        this.visibilityControls = {
            ...this.visibilityControls,
            [name]: visible,
        };
    }

    /**
     * Hide all visibility controls associated with the current instance.
     */
    hideAll() {
        const controls = Object.keys(this.visibilityControls);

        for (let i = 0; i < controls.length; i++) {
            const control = controls.objectAt(i);
            this.hide(control);
        }
    }

    /**
     * Show all visibility controls associated with the current instance.
     */
    showAll() {
        const controls = Object.keys(this.visibilityControls);

        for (let i = 0; i < controls.length; i++) {
            const control = controls.objectAt(i);
            this.show(control);
        }
    }

    /**
     * Hides a specific element by name using a visibility control.
     *
     * @function
     * @param {string} name - The name or identifier of the element to hide.
     */
    hide(name) {
        if (isArray(name)) {
            return name.forEach(this.hide.bind(this));
        }

        this.createVisibilityControl(name, false);
    }

    /**
     * Shows a specific element by name using a visibility control.
     *
     * @function
     * @param {string} name - The name or identifier of the element to show.
     */
    show(name) {
        if (isArray(name)) {
            return name.forEach(this.show.bind(this));
        }

        this.createVisibilityControl(name, true);
    }

    /**
     * Toggles the visibility of a control by its name.
     * Calls `hide()` if the control is currently visible, and `show()` otherwise.
     *
     * @param {string} name - The name of the control to toggle.
     * @memberof LiveMapComponent
     */
    toggleVisibility(name) {
        if (this.isVisible(name)) {
            this.hide(name);
        } else {
            this.show(name);
        }
    }

    /**
     * Check if a specific element or feature is currently visible based on its name.
     *
     * @param {string} name - The name of the element or feature to check visibility for.
     * @returns {boolean} Returns `true` if the element or feature is currently visible, `false` otherwise.
     * @memberof LiveMapComponent
     */
    isVisible(name) {
        return this.visibilityControls[name] === true;
    }

    /**
     * Caches the original state of a resource array.
     * @param {string} name - Name of the resource array (e.g., 'vehicles', 'drivers').
     * @memberof LiveMapComponent
     */
    cacheOriginalResources(name) {
        if (!this.originalResources[name]) {
            this.originalResources[name] = [...this[name]];
        }
    }

    /**
     * Retrieves the original resources array for a given name.
     * @param {string} name - Name of the resource array (e.g., 'vehicles', 'drivers').
     * @returns {Array} - The original array of resources; an empty array if not set.
     * @memberof LiveMapComponent
     */
    getOriginalResources(name) {
        if (isArray(this.originalResources[name])) {
            return this.originalResources[name];
        }

        return [];
    }

    /**
     * Shows all online and offline resources for a given name.
     * @param {string} name - Name of the resource array (e.g., 'vehicles', 'drivers').
     * @memberof LiveMapComponent
     */
    showAllOnlineOffline(name) {
        this.show(name);
        this.showOnline(name);
        this.showOffline(name);
    }

    /**
     * Hides all online and offline resources for a given name.
     * @param {string} name - Name of the resource array (e.g., 'vehicles', 'drivers').
     * @memberof LiveMapComponent
     */
    hideAllOnlineOffline(name) {
        this.hide(name);
        this.hideOnline(name);
        this.hideOffline(name);
    }

    /**
     * Toggles the visibility of all online and offline resources for a given name.
     * @param {string} name - Name of the resource array (e.g., 'vehicles', 'drivers').
     * @memberof LiveMapComponent
     */
    toggleAllOnlineOffline(name) {
        if (this.isVisible(name)) {
            this.hideAllOnlineOffline(name);
        } else {
            this.showAllOnlineOffline(name);
        }
    }

    /**
     * Toggles the visibility of online resources for a given array.
     * @method toggleOnline
     * @param {string} name - The name of the resource array (e.g., 'vehicles', 'drivers').
     * @memberof LiveMapComponent
     */
    toggleOnline(name) {
        const visibilityControlName = `online${classify(name)}`;

        if (this.isVisible(visibilityControlName)) {
            this.hideOnline(name);
        } else {
            this.showOnline(name);
        }
    }

    /**
     * Toggles the visibility of offline resources for a given array.
     * @method toggleOffline
     * @param {string} name - The name of the resource array (e.g., 'vehicles', 'drivers').
     * @memberof LiveMapComponent
     */
    toggleOffline(name) {
        const visibilityControlName = `offline${classify(name)}`;

        if (this.isVisible(visibilityControlName)) {
            this.hideOffline(name);
        } else {
            this.showOffline(name);
        }
    }

    /**
     * Hides online resources from a given array and updates it.
     * @method hideOnline
     * @param {string} name - The name of the resource array (e.g., 'vehicles', 'drivers').
     * @memberof LiveMapComponent
     */
    hideOnline(name) {
        this[name] = this.getOriginalResources(name).filter((resource) => !resource.online);

        // track with visibility controls
        this.createVisibilityControl(`online${classify(name)}`, false);
    }

    /**
     * Shows online resources from a given array and updates it.
     * @method showOnline
     * @param {string} name - The name of the resource array (e.g., 'vehicles', 'drivers').
     * @memberof LiveMapComponent
     */
    showOnline(name) {
        this[name] = this.getOriginalResources(name).filter((resource) => resource.online);

        // track with visibility controls
        this.createVisibilityControl(`online${classify(name)}`, true);
    }

    /**
     * Hides offline resources from a specified array.
     * @param {string} name - Name of the resource array (e.g., 'vehicles', 'drivers').
     * @memberof LiveMapComponent
     */
    hideOffline(name) {
        this[name] = this.getOriginalResources(name).filter((resource) => resource.online);

        // track with visibility controls
        this.createVisibilityControl(`offline${classify(name)}`, false);
    }

    /**
     * Shows offline resources from a specified array.
     * @param {string} name - Name of the resource array (e.g., 'vehicles', 'drivers').
     * @memberof LiveMapComponent
     */
    showOffline(name) {
        this[name] = this.getOriginalResources(name).filter((resource) => !resource.online);

        // track with visibility controls
        this.createVisibilityControl(`offline${classify(name)}`, true);
    }

    /**
     * Toggles the context menu item for enabling/disabling draw controls.
     *
     * @param {Object} [options] - Optional settings for the context menu item.
     * @param {string} [options.onText='Hide draw controls...'] - Text to display when enabling draw controls.
     * @param {string} [options.offText='Enable draw controls...'] - Text to display when disabling draw controls.
     * @param {string} [options.callback=function] - Callback function to trigger after toggle.
     * @memberof LiveMapComponent
     */
    toggleDrawControlContextMenuItem(options = {}) {
        const toggle = !this.isVisible('drawControls');

        this.leafletContextmenuManager.toggleContextMenuItem('map', 'draw controls', {
            onText: 'Hide draw controls...',
            offText: 'Enable draw controls...',
            toggle,
            callback: (isToggled) => {
                if (isToggled) {
                    this.showDrawControls();
                } else {
                    this.hideDrawControls();
                }
            },
            ...options,
        });
    }

    /**
     * Removes a specific service area from the context menu.
     *
     * @param {Object} serviceArea - The service area to be removed from the context menu.
     * @memberof LiveMapComponent
     */
    removeServiceAreaFromContextMenu(serviceArea) {
        this.leafletContextmenuManager.removeItemFromContextMenu('map', `Focus Service Area: ${serviceArea.name}`);
    }

    /**
     * Get a Leaflet layer from the map based on its ID.
     *
     * @param {string} id - The ID of the Leaflet layer to retrieve.
     * @returns {Object|null} The found Leaflet layer or `null` if not found.
     * @memberof LiveMapComponent
     */
    getLeafletLayerById(id) {
        return this.leafletMapManager.getLeafletLayerById(this.leafletMap, id);
    }

    /**
     * Find a specific Leaflet layer on the map using a callback function.
     *
     * @param {Function} callback - A callback function that defines the condition for finding the layer.
     * @returns {Object|null} The found Leaflet layer or `null` if not found.
     * @memberof LiveMapComponent
     */
    findLeafletLayer(callback) {
        return this.leafletMapManager.findLeafletLayer(this.leafletMap, callback);
    }

    /**
     * Find an editable layer in the collection by its record ID.
     *
     * @param {Object} record - The record with the ID used for lookup.
     * @returns {Layer|null} The found editable layer, or null if not found.
     * @memberof LiveMapComponent
     */
    getLeafletLayerByRecordId(record) {
        const id = getWithDefault(record, 'id', record);
        let targetLayer = null;

        this.leafletMap.eachLayer((layer) => {
            // Check if the layer has an ID property
            if (layer.record_id === id) {
                targetLayer = layer;
            }
        });

        return targetLayer;
    }

    /**
     * Push an editable layer to the collection of editable layers.
     *
     * @param {Layer} layer - The layer to be added to the collection.
     * @memberof LiveMapComponent
     */
    pushEditableLayer(layer) {
        if (!this.editableLayers.includes(layer)) {
            this.editableLayers.pushObject(layer);
        }
    }

    /**
     * Remove an editable layer from the collection by its record ID.
     *
     * @param {Object} record - The record with the ID used for removal.
     * @memberof LiveMapComponent
     */
    removeEditableLayerByRecordId(record) {
        const id = getWithDefault(record, 'id', record);
        const index = this.editableLayers.findIndex((layer) => layer.record_id === id);
        const layer = this.editableLayers.objectAt(index);

        if (this.drawFeatureGroup) {
            this.drawFeatureGroup.addLayer(layer);
            this.editableLayers.removeAt(index);
        }
    }

    /**
     * Find an editable layer in the collection by its record ID.
     *
     * @param {Object} record - The record with the ID used for lookup.
     * @returns {Layer|null} The found editable layer, or null if not found.
     * @memberof LiveMapComponent
     */
    findEditableLayerByRecordId(record) {
        const id = getWithDefault(record, 'id', record);
        return this.editableLayers.find((layer) => layer.record_id === id);
    }

    /**
     * Peek a record for a given layer by its record ID and type.
     *
     * @param {Layer} layer - The layer associated with a record.
     * @returns {Object|null} The peeked record, or null if not found.
     * @memberof LiveMapComponent
     */
    peekRecordForLayer(layer) {
        if (layer.record_id && layer.record_type) {
            return this.store.peekRecord(dasherize(layer.record_type), layer.record_id);
        }

        return null;
    }

    /**
     * Sets the drawer component context api.
     *
     * @param {Object} drawerApi
     * @memberof LiveMapComponent
     */
    @action setDrawerContext(drawerApi) {
        this.drawer = drawerApi;

        if (typeof this.args.onDrawerReady === 'function') {
            this.args.onDrawerReady(...arguments);
        }
    }

    /**
     * Handle the 'drawstop' event.
     *
     * @param {Event} event - The 'drawstop' event object.
     * @param {Layer} layer - The layer associated with the event.
     * @memberof LiveMapComponent
     */
    @action onDrawDrawstop(event, layer) {
        this.serviceAreas.createGenericLayer(event, layer);
    }

    /**
     * Handle the 'deleted' event for drawn elements.
     *
     * @param {Event} event - The 'deleted' event object.
     * @memberof LiveMapComponent
     */
    @action onDrawDeleted(event) {
        /** @var {L.LayerGroup} layers  */
        const { layers } = event;

        const records = layers.getLayers().map(this.peekRecordForLayer).filter(Boolean);
        const requests = records.map((record) => {
            this.blurServiceArea(record);
            this.removeServiceAreaFromContextMenu(record);

            return record.destroyRecord();
        });

        allSettled(requests).then(() => {
            records.forEach((record) => this.serviceAreas.removeFromCache(record));
        });
    }

    /**
     * Handle the 'edited' event for drawn elements.
     *
     * @param {Event} event - The 'edited' event object.
     * @memberof LiveMapComponent
     */
    @action onDrawEdited(event) {
        /** @var {L.LayerGroup} layers  */
        const { layers } = event;

        const requests = layers.getLayers().map((layer) => {
            const record = this.peekRecordForLayer(layer);

            let border;

            if (layer.record_type === 'zone') {
                border = this.serviceAreas.layerToTerraformerPrimitive(layer);
            } else {
                border = this.serviceAreas.layerToTerraformerMultiPolygon(layer);
            }

            record.set('border', border);

            return record.save();
        });

        allSettled(requests);
    }

    /**
     * Handle the addition of a service area layer.
     *
     * @param {ServiceAreaModel} serviceArea - The service area object.
     * @param {Event} event - The event object associated with the addition.
     * @memberof LiveMapComponent
     */
    @action onServiceAreaLayerAdded(serviceArea, event) {
        const { target } = event;

        set(target, 'record_id', serviceArea.id);
        set(target, 'record_type', 'service-area');

        // set the layer instance to the serviceArea model
        set(serviceArea, '_layer', target);

        if (this.drawFeatureGroup) {
            // add to draw feature group
            this.drawFeatureGroup.addLayer(target);
        }

        // this.flyToBoundsOnly(target);
        this.createServiceAreaContextMenu(serviceArea, target);
        this.pushEditableLayer(target);
    }

    /**
     * Handle the addition of a zone layer.
     *
     * @param {ZoneModel} zone - The zone object.
     * @param {Event} event - The event object associated with the addition.
     * @memberof LiveMapComponent
     */
    @action onZoneLayerAdd(zone, event) {
        const { target } = event;

        set(target, 'record_id', zone.id);
        set(target, 'record_type', 'zone');

        // set the layer instance to the zone model
        set(zone, '_layer', target);

        if (this.drawFeatureGroup) {
            // add to draw feature group
            this.drawFeatureGroup.addLayer(target);
        }

        this.createZoneContextMenu(zone, target);
        this.pushEditableLayer(target);
    }

    /**
     * Handle the creation of the draw feature group.
     *
     * @param {DrawFeatureGroup} drawFeatureGroup - The draw feature group instance.
     * @memberof LiveMapComponent
     */
    @action onDrawFeatureGroupCreated(drawFeatureGroup) {
        this.drawFeatureGroup = drawFeatureGroup;
    }

    /**
     * Handle the addition of a driver marker.
     *
     * @param {DriverModel} driver - The driver object.
     * @param {Event} event - The event object associated with the addition.
     * @memberof LiveMapComponent
     */
    @action onDriverAdded(driver, event) {
        const { target } = event;

        set(target, 'record_id', driver.id);
        set(target, 'record_type', 'driver');

        // set the marker instance to the driver model
        set(driver, '_marker', target);

        this.createDriverContextMenu(driver, target);
    }

    /**
     * Handle the click event of a driver marker.
     *
     * @param {DriverModel} driver - The driver object.
     * @param {Event} event - The event object associated with the addition.
     * @memberof LiveMapComponent
     */
    @action onDriverClicked(driver) {
        this.contextPanel.clear();
        this.contextPanel.focus(driver);
    }

    /**
     * Handle the addition of a vehicle marker.
     *
     * @param {VehicleModel} vehicle - The vehicle object.
     * @param {Event} event - The event object associated with the addition.
     * @memberof LiveMapComponent
     */
    @action onVehicleAdded(vehicle, event) {
        const { target } = event;

        set(target, 'record_id', vehicle.id);
        set(target, 'record_type', 'vehicle');

        // set the marker instance to the vehicle model
        set(vehicle, '_marker', target);

        this.createVehicleContextMenu(vehicle, target);
    }

    /**
     * Handle the addition of a place marker.
     *
     * @param {PlaceModel} place - The place object.
     * @param {Event} event - The event object associated with the addition.
     * @memberof LiveMapComponent
     */
    @action onPlaceAdded(place, event) {
        const { target } = event;

        set(target, 'record_id', place.id);
        set(target, 'record_type', 'place');

        // set the marker instance to the vehicle model
        set(place, '_marker', target);
    }

    /**
     * Handle the click event of a vehicle marker.
     *
     * @param {VehicleModel} vehicle - The vehicle object.
     * @param {Event} event - The event object associated with the addition.
     * @memberof LiveMapComponent
     */
    @action onVehicleClicked(vehicle) {
        this.contextPanel.clear();
        this.contextPanel.focus(vehicle);
    }

    /**
     * Handle the creation of the draw control.
     *
     * @param {DrawControl} drawControl - The draw control instance.
     * @memberof LiveMapComponent
     */
    @action onDrawControlCreated(drawControl) {
        this.drawControl = drawControl;
    }

    /**
     * Hide the draw controls on the map.
     *
     * @param {Object} [options={}] - Additional options.
     * @param {string|boolean} [options.text] - Text to set for the menu item or `true` to set the default text.
     * @param {function} [options.callback] - A callback function to execute.
     * @memberof LiveMapComponent
     */
    @action hideDrawControls(options = {}) {
        this.hide('drawControls');

        const text = getWithDefault(options, 'text');
        const callback = getWithDefault(options, 'callback');

        if (typeof callback === 'function') {
            callback();
        }

        if (typeof text === 'string') {
            this.leafletContextmenuManager.changeMenuItemText('map', 'draw controls', text);
        }

        if (text === true) {
            this.leafletContextmenuManager.changeMenuItemText('map', 'draw controls', 'Enable draw controls...');
        }

        if (this.drawControl) {
            this.leafletMap.removeControl(this.drawControl);
        }
    }

    /**
     * Show the draw controls on the map.
     *
     * @param {Object} [options={}] - Additional options.
     * @param {string|boolean} [options.text] - Text to set for the menu item or `true` to set the default text.
     * @param {function} [options.callback] - A callback function to execute.
     * @memberof LiveMapComponent
     */
    @action showDrawControls(options = {}) {
        this.show('drawControls');

        const text = getWithDefault(options, 'text');
        const callback = getWithDefault(options, 'callback');

        if (typeof callback === 'function') {
            callback();
        }

        if (typeof text === 'string') {
            this.leafletContextmenuManager.changeMenuItemText('map', 'draw controls', text);
        }

        if (text === true) {
            this.leafletContextmenuManager.changeMenuItemText('map', 'draw controls', 'Hide draw controls...');
        }

        if (this.drawControl) {
            this.leafletMap.addControl(this.drawControl);
        }
    }

    /**
     * Focus on a layer associated with a record.
     *
     * @param {Object} record - The record to focus on.
     * @memberof LiveMapComponent
     */
    @action focusLayerBoundsByRecord(record) {
        const layer = this.getLeafletLayerByRecordId(record);

        if (layer) {
            this.flyToBoundsOnly(layer);
        }
    }

    /**
     * Fly to a service area layer on the map.
     *
     * @param {ServiceAreaModel} serviceArea - The service area object to fly to.
     * @memberof LiveMapComponent
     */
    @action flyToServiceArea(serviceArea) {
        const layer = this.findEditableLayerByRecordId(serviceArea);

        if (layer) {
            this.flyToBoundsOnly(layer);
        }
    }

    /**
     * Focus on a service area by activating it and then flying to it on the map.
     *
     * @param {ServiceArea} serviceArea - The service area to focus on.
     * @memberof LiveMapComponent
     */
    @action focusServiceArea(serviceArea) {
        this.activateServiceArea(serviceArea);

        later(
            this,
            () => {
                this.flyToServiceArea(serviceArea);
            },
            100
        );
    }

    /**
     * Blur all service areas except for those specified in the 'except' array.
     *
     * @param {Array} except - An array of records to exclude from blurring.
     * @memberof LiveMapComponent
     */
    blurAllServiceAreas(except = []) {
        if (!isArray(except)) {
            except = [];
        }

        // map except into ids only
        except = except
            .filter(Boolean)
            .filter((record) => !record?.id)
            .map((record) => record.id);

        for (let i = 0; i < this.activeServiceAreas.length; i++) {
            const serviceArea = this.activeServiceAreas.objectAt(i);

            if (isArray(except) && except.includes(serviceArea.id)) {
                continue;
            }

            this.blurServiceArea(serviceArea);
        }

        for (let i = 0; i < this.editableLayers.length; i++) {
            const layer = this.editableLayers.objectAt(i);

            if (isArray(except) && except.includes(layer.record_id)) {
                continue;
            }

            this.editableLayers.removeObject(layer);
        }
    }

    /**
     * Focus on all service areas except for those specified in the 'except' array by activating them.
     *
     * @param {Array} except - An array of records to exclude from activation.
     * @memberof LiveMapComponent
     */
    focusAllServiceAreas(except = []) {
        if (!isArray(except)) {
            except = [];
        }

        // map except into ids only
        except = except
            .filter(Boolean)
            .filter((record) => !record?.id)
            .map((record) => record.id);

        for (let i = 0; i < this.serviceAreaRecords.length; i++) {
            const serviceArea = this.serviceAreaRecords.objectAt(i);

            if (isArray(except) && except.includes(serviceArea.id)) {
                continue;
            }

            this.activateServiceArea(serviceArea);
        }
    }

    /**
     * Blur a specific service area by removing it from the active service areas.
     *
     * @param {ServiceAreaModel} serviceArea - The service area to blur.
     * @memberof LiveMapComponent
     */
    blurServiceArea(serviceArea) {
        if (this.activeServiceAreas.includes(serviceArea)) {
            this.activeServiceAreas.removeObject(serviceArea);
        }
    }

    /**
     * Activate a service area by adding it to the active service areas.
     *
     * @param {ServiceAreaModel} serviceArea - The service area to activate.
     * @memberof LiveMapComponent
     */
    activateServiceArea(serviceArea) {
        if (!this.activeServiceAreas.includes(serviceArea)) {
            this.activeServiceAreas.pushObject(serviceArea);
        }
    }

    /**
     * Show coordinates information by displaying them as an info notification.
     *
     * @param {Event} event - The event containing latitude and longitude information.
     * @memberof LiveMapComponent
     */
    @action showCoordinates(event) {
        this.notifications.info(event.latlng);
    }

    /**
     * Center the map on a specific location provided in the event.
     *
     * @param {Event} event - The event containing the target location (latlng).
     * @memberof LiveMapComponent
     */
    @action centerMap(event) {
        this.leafletMap.panTo(event.latlng);
    }

    /**
     * Zoom in on the map.
     *
     * @memberof LiveMapComponent
     */
    @action zoomIn() {
        this.leafletMap.zoomIn();
    }

    /**
     * Zoom out on the map.
     *
     * @memberof LiveMapComponent
     */
    @action zoomOut() {
        this.leafletMap.zoomOut();
    }

    /**
     * Set the maximum bounds of the map based on the provided layer's bounds.
     *
     * @param {Layer} layer - The layer used to determine the map's maximum bounds.
     * @memberof LiveMapComponent
     */
    setMaxBoundsFromLayer(layer) {
        if (layer && typeof layer.getBounds === 'function') {
            const bounds = layer.getBounds();

            this.leafletMap.flyToBounds(bounds);
            this.leafletMap.setMaxBounds(bounds);
        }
    }

    /**
     * Fly to and focus on a specific layer's bounds on the map.
     *
     * @param {Layer} layer - The layer to focus on.
     * @memberof LiveMapComponent
     */
    flyToBoundsOnly(layer) {
        if (layer && typeof layer.getBounds === 'function') {
            const bounds = layer.getBounds();

            this.leafletMap.flyToBounds(bounds);
        }
    }

    /**
     * Focus on a specific layer and optionally zoom in/out on it.
     *
     * @param {Layer} layer - The layer to focus on.
     * @param {number} zoom - The zoom level for the focus operation.
     * @param {Object} options - Additional options for the focus operation.
     * @memberof LiveMapComponent
     */
    @action focusLayer(layer, zoom, options = {}) {
        this.leafletMapManager.flyToLayer(this.leafletMap, layer, zoom, options);

        if (typeof options.onAfterFocus === 'function') {
            options.onAfterFocus(layer);
        }
    }

    /**
     * Focuses the Leaflet map on a specific layer associated with a record.
     *
     * @param {Object} record - The record associated with the target layer.
     * @param {number} zoom - The desired zoom level for the map.
     * @param {Object} [options={}] - Additional options for the map focus.
     * @returns {void}
     *
     * @example
     * focusLayerByRecord(recordData, 12, { animate: true });
     */
    @action focusLayerByRecord(record, zoom, options = {}) {
        const layer = this.getLeafletLayerByRecordId(record);

        if (layer) {
            this.focusLayer(layer, zoom, options);
        }

        if (typeof options.onAfterFocusWithRecord === 'function') {
            options.onAfterFocusWithRecord(record, layer);
        }
    }

    /**
     * Create a context menu for the map with various options.
     *
     * @param {L.Map} map - The map to which the context menu is attached.
     * @memberof LiveMapComponent
     */
    @action createMapContextMenu(map) {
        const contextmenuItems = [
            {
                text: 'Show coordinates...',
                callback: this.showCoordinates,
                index: 0,
            },
            {
                text: 'Center map here...',
                callback: this.centerMap,
                index: 1,
            },
            {
                text: 'Zoom in...',
                callback: this.zoomIn,
                index: 2,
            },
            {
                text: 'Zoom out...',
                callback: this.zoomOut,
                index: 3,
            },
            {
                text: this.isVisible('drawControls') ? `Hide draw controls...` : `Enable draw controls...`,
                callback: this.toggleDrawControlContextMenuItem.bind(this),
                index: 4,
            },
            {
                separator: true,
            },
            {
                text: 'Create new Service Area...',
                callback: this.serviceAreas.createServiceArea,
                index: 5,
            },
        ];

        // Add Service Area Context Menu Items
        const serviceAreas = this.serviceAreas.getFromCache();

        if (isArray(serviceAreas) && serviceAreas.length) {
            contextmenuItems.pushObject({
                separator: true,
            });

            // Add for each Service Area
            for (let i = 0; i < serviceAreas.length; i++) {
                const serviceArea = serviceAreas.objectAt(i);
                const nextIndex = contextmenuItems.length + 2;

                contextmenuItems.pushObject({
                    text: `Focus Service Area: ${serviceArea.name}`,
                    callback: () => this.focusServiceArea(serviceArea),
                    index: nextIndex,
                });
            }
        }

        // create contextmenu registry
        const contextmenuRegistry = this.leafletContextmenuManager.createContextMenu('map', map, contextmenuItems);

        // trigger that contextmenu registry was created
        this.universe.createRegistryEvent('contextmenu:map', 'created', contextmenuRegistry, this.leafletContextmenuManager);

        return contextmenuRegistry;
    }

    /**
     * Rebuild the context menu for the map.
     * This function calls the `createMapContextMenu` method.
     * @memberof LiveMapComponent
     */
    rebuildMapContextMenu() {
        this.createMapContextMenu(this.leafletMap);
    }

    /**
     * Create a context menu for a driver marker on the map.
     *
     * @param {DriverModel} driver - The driver associated with the marker.
     * @param {L.Layer} layer - The layer representing the driver marker.
     * @memberof LiveMapComponent
     */
    @action createDriverContextMenu(driver, layer) {
        let contextmenuItems = [
            {
                separator: true,
            },
            {
                text: `View Driver: ${driver.name}`,
                callback: () => this.contextPanel.focus(driver),
            },
            {
                text: `Edit Driver: ${driver.name}`,
                callback: () => this.contextPanel.focus(driver, 'editing'),
            },
            {
                text: `Delete Driver: ${driver.name}`,
                callback: () => this.crud.delete(driver),
            },
            {
                text: `View Vehicle for: ${driver.name}`,
                callback: () => this.contextPanel.focus(driver.vehicle),
            },
        ];

        // append items from universe registry
        const registeredContextMenuItems = this.universe.getMenuItemsFromRegistry('contextmenu:driver');
        if (isArray(registeredContextMenuItems)) {
            contextmenuItems = [
                ...contextmenuItems,
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
        const contextmenuRegistry = this.leafletContextmenuManager.createContextMenu(`driver:${driver.public_id}`, layer, contextmenuItems, { driver });

        // trigger that contextmenu registry was created
        this.universe.createRegistryEvent('contextmenu:driver', 'created', contextmenuRegistry, this.leafletContextmenuManager);

        return contextmenuRegistry;
    }

    /**
     * Create a context menu for a vehicle marker on the map.
     *
     * @param {Vehicle} vehicle - The vehicle associated with the marker.
     * @param {Layer} layer - The layer representing the vehicle marker.
     * @memberof LiveMapComponent
     */
    @action createVehicleContextMenu(vehicle, layer) {
        let contextmenuItems = [
            {
                separator: true,
            },
            {
                text: `View Vehicle: ${vehicle.displayName}`,
                callback: () => this.contextPanel.focus(vehicle),
            },
            {
                text: `Edit Vehicle: ${vehicle.displayName}`,
                callback: () => this.contextPanel.focus(vehicle, 'editing'),
            },
            {
                text: `Delete Vehicle: ${vehicle.displayName}`,
                callback: () => this.crud.delete(vehicle),
            },
        ];

        // append items from universe registry
        const registeredContextMenuItems = this.universe.getMenuItemsFromRegistry('contextmenu:vehicle');
        if (isArray(registeredContextMenuItems)) {
            contextmenuItems = [
                ...contextmenuItems,
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
        const contextmenuRegistry = this.leafletContextmenuManager.createContextMenu(`vehicle:${vehicle.public_id}`, layer, contextmenuItems, { vehicle });

        // trigger that contextmenu registry was created
        this.universe.createRegistryEvent('contextmenu:vehicle', 'created', contextmenuRegistry, this.leafletContextmenuManager);

        return contextmenuRegistry;
    }

    /**
     * Create a context menu for a zone layer on the map.
     *
     * @param {ZoneModel} zone - The zone associated with the layer.
     * @param {Layer} layer - The layer representing the zone.
     * @memberof LiveMapComponent
     */
    @action createZoneContextMenu(zone, layer) {
        let contextmenuItems = [
            {
                separator: true,
            },
            {
                text: `Edit Zone: ${zone.name}`,
                callback: () => this.serviceAreas.editZone(zone),
            },
            {
                text: `Delete Zone: ${zone.name}`,
                callback: () =>
                    this.serviceAreas.deleteZone(zone, {
                        onFinish: () => {
                            this.removeEditableLayerByRecordId(zone);
                        },
                    }),
            },
            {
                text: `Assign Fleet to Zone: (${zone.name})`,
                callback: () => {},
            },
        ];

        // create contextmenu registry
        const contextmenuRegistry = this.leafletContextmenuManager.createContextMenu(`zone:${zone.public_id}`, layer, contextmenuItems, { zone });

        // trigger that contextmenu registry was created
        this.universe.createRegistryEvent('contextmenu:zone', 'created', contextmenuRegistry, this.leafletContextmenuManager);

        return contextmenuRegistry;
    }

    /**
     * Create a context menu for a service area layer on the map.
     *
     * @param {ServiceAreaModel} serviceArea - The service area associated with the layer.
     * @param {Layer} layer - The layer representing the service area.
     * @memberof LiveMapComponent
     */
    @action createServiceAreaContextMenu(serviceArea, layer) {
        let contextmenuItems = [
            {
                separator: true,
            },
            {
                text: `Blur Service Area: ${serviceArea.name}`,
                callback: () => this.blurServiceArea(serviceArea),
            },
            {
                text: `Create Zone within: ${serviceArea.name}`,
                callback: () => this.serviceAreas.createZone(serviceArea),
            },
            {
                text: `Assign Fleet to Service Area: ${serviceArea.name}`,
                callback: () => {},
            },
            {
                text: `Edit Service Area: ${serviceArea.name}`,
                callback: () => this.serviceAreas.editServiceAreaDetails(serviceArea),
            },
            {
                text: `Delete Service Area: ${serviceArea.name}`,
                callback: () =>
                    this.serviceAreas.deleteServiceArea(serviceArea, {
                        onFinish: () => {
                            this.rebuildMapContextMenu();
                            this.removeEditableLayerByRecordId(serviceArea);
                        },
                    }),
            },
        ];

        // create contextmenu registry
        const contextmenuRegistry = this.leafletContextmenuManager.createContextMenu(`service-area:${serviceArea.public_id}`, layer, contextmenuItems, { serviceArea });

        // trigger that contextmenu registry was created
        this.universe.createRegistryEvent('contextmenu:service-area', 'created', contextmenuRegistry, this.leafletContextmenuManager);

        return contextmenuRegistry;
    }

    /**
     * Listens for events on the company channel and logs incoming data.
     *
     * This function sets up a WebSocket connection, subscribes to the company-specific channel,
     * and listens for events. When events are received, it logs them to the console.
     *
     * @async
     * @function
     */
    async listen() {
        // setup socket
        const socket = this.socket.instance();

        // listen on company channel
        const channelId = `company.${this.currentUser.companyId}`;
        const channel = socket.subscribe(channelId);

        // track channel
        this.channels.pushObject(channel);

        // listen to channel for events
        await channel.listener('subscribe').once();

        // get incoming data and console out
        (async () => {
            for await (let output of channel) {
                const { event, data } = output;

                console.log(`[channel ${channelId}]`, output, event, data);
            }
        })();
    }

    /**
     * Watches and manages moving objects (e.g., drivers or vehicles) on the LiveMapComponent.
     *
     * This function can be used to watch different types of moving objects by specifying
     * the 'type' parameter.
     *
     * @action
     * @function
     * @param {string} objectType - The type of moving object to watch (e.g., 'drivers', 'vehicles').
     * @param {Array} movingObjects - An array of moving objects to watch.
     */
    watchMovingObjects(objectType, objects = []) {
        // Setup socket
        const socket = this.socket.instance();

        // Listen for moving objects
        for (let i = 0; i < objects.length; i++) {
            const movingObject = objects.objectAt(i);
            this.listenForMovingObject(objectType, movingObject, socket);
        }
    }

    /**
     * Listens for events related to a specific type of moving object (e.g., driver or vehicle) and manages the associated marker.
     *
     * This function subscribes to the channel corresponding to the provided 'objectType' and the specific 'movingObject'
     * to listen for location-related events. It processes and updates the associated marker when events are received.
     *
     * @async
     * @function
     * @param {string} objectType - The type of moving object being watched (e.g., 'drivers', 'vehicles').
     * @param {Object} movingObject - The specific moving object to track.
     * @param {Socket} socket - The WebSocket instance used for communication.
     */
    async listenForMovingObject(objectType, movingObject, socket) {
        // Listen on the specific channel
        const channelId = `${objectType}.${movingObject.id}`;
        const channel = socket.subscribe(channelId);

        // Track the channel
        this.channels.pushObject(channel);

        // Listen to the channel for events
        await channel.listener('subscribe').once();

        // Initialize an empty buffer to store incoming events
        const eventBuffer = [];

        // Time to wait in milliseconds before processing buffered events
        const bufferTime = 1000 * 10;

        // Function to process buffered events
        const processBuffer = () => {
            // Sort events by created_at
            eventBuffer.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));

            // Process sorted events
            for (const output of eventBuffer) {
                const { event, data } = output;

                // log incoming event
                console.log(`${event} - #${data.additionalData.index} (${output.created_at}) [ ${data.location.coordinates.join(' ')} ]`);

                // get movingObject marker
                const objectMarker = movingObject._layer;

                if (objectMarker) {
                    // Update the object's heading degree
                    objectMarker.setRotationAngle(data.heading);
                    // Move the object's marker to new coordinates
                    objectMarker.slideTo(data.location.coordinates, { duration: 2000 });
                }
            }

            // Clear the buffer
            eventBuffer.length = 0;
        };

        // Start a timer to process the buffer at intervals
        setInterval(processBuffer, bufferTime);

        // Get incoming data and console out
        (async () => {
            for await (let output of channel) {
                const { event } = output;

                if (event === `${objectType}.location_changed` || event === `${objectType}.simulated_location_changed`) {
                    // Add the incoming event to the buffer
                    eventBuffer.push(output);
                }
            }
        })();
    }

    /**
     * Close all socket channels associated subscribed to.
     * @memberof LiveMapComponent
     */
    @action closeChannels() {
        if (isArray(this.channels)) {
            for (let i = 0; i < this.channels.length; i++) {
                const channel = this.channels.objectAt(i);

                channel.close();
            }
        }
    }

    /**
     * Retrieve the initial coordinates for the map view.
     *
     * @returns {Promise} A promise that resolves to an object containing latitude and longitude.
     * @memberof LiveMapComponent
     */
    getInitialCoordinates() {
        const initialCoordinates = {
            latitude: DEFAULT_LATITUDE,
            longitude: DEFAULT_LONGITUDE,
        };

        const getCoordinateFromNavigator = () => {
            return new Promise((resolve) => {
                // eslint-disable-next-line no-undef
                if (window.navigator && window.navigator.geolocation) {
                    // eslint-disable-next-line no-undef
                    return navigator.geolocation.getCurrentPosition(
                        ({ coords }) => {
                            const { latitude, longitude } = coords;

                            initialCoordinates.latitude = latitude;
                            initialCoordinates.longitude = longitude;

                            resolve(initialCoordinates);
                        },
                        () => {
                            // if failed use default user lat/lng
                            initialCoordinates.latitude = this.getLocalLatitude();
                            initialCoordinates.longitude = this.getLocalLongitude();

                            resolve(initialCoordinates);
                        }
                    );
                }
            });
        };

        return new Promise((resolve) => {
            // get location from active orders
            this.fetch
                .get('fleet-ops/live/coordinates')
                .then((coordinates) => {
                    if (!coordinates) {
                        return getCoordinateFromNavigator().then((navigatorCoordinates) => {
                            resolve(navigatorCoordinates);
                        });
                    }

                    // from the `get-active-order-coordinates` the responded coordinates will always be [longitude, latitude]
                    // const [latitude, longitude] = extractCoordinates(coordinates.firstObject.coordinates);
                    const [longitude, latitude] = coordinates.filter((point) => point.cordinates[0] !== 0).firstObject?.coordinates;

                    initialCoordinates.latitude = latitude;
                    initialCoordinates.longitude = longitude;

                    resolve(initialCoordinates);
                })
                .catch(() => {
                    getCoordinateFromNavigator().then(resolve);
                });
        });
    }

    /**
     * Fetch service areas and cache them if not already cached.
     *
     * @returns {Promise} A promise that resolves to an array of service area records.
     * @memberof LiveMapComponent
     */
    fetchServiceAreas() {
        this.isLoading = true;

        return new Promise((resolve) => {
            const cachedRecords = this.serviceAreas?.getFromCache('serviceAreas', 'service-area');

            if (cachedRecords) {
                resolve(cachedRecords);
            }

            return this.store
                .query('service-area', { with: ['zones'] })
                .then((serviceAreaRecords) => {
                    this.appCache.setEmberData('serviceAreas', serviceAreaRecords);
                    resolve(serviceAreaRecords);
                })
                .finally(() => {
                    this.isLoading = false;
                });
        });
    }

    /**
     * Get the local latitude for the map view.
     *
     * @returns {number} The local latitude.
     * @memberof LiveMapComponent
     */
    getLocalLatitude() {
        const whois = this.currentUser.getOption('whois');
        const latitude = this.appCache.get('map_latitude');

        return latitude || whois?.latitude || DEFAULT_LATITUDE;
    }

    /**
     * Get the local longitude for the map view.
     *
     * @returns {number} The local longitude.
     * @memberof LiveMapComponent
     */
    getLocalLongitude() {
        const whois = this.currentUser.getOption('whois');
        const longitude = this.appCache.get('map_longitude');

        return longitude || whois?.longitude || DEFAULT_LONGITUDE;
    }
}
