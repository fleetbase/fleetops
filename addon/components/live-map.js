import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, computed, set } from '@ember/object';
import { isArray } from '@ember/array';
import { isBlank } from '@ember/utils';
import { dasherize } from '@ember/string';
import { alias } from '@ember/object/computed';
import { guidFor } from '@ember/object/internals';
import { later } from '@ember/runloop';
import { allSettled } from 'rsvp';

const DEFAULT_LATITUDE = 1.369;
const DEFAULT_LONGITUDE = 103.8864;

export default class LiveMapComponent extends Component {
    @service store;
    @service fetch;
    @service socket;
    @service currentUser;
    @service notifications;
    @service serviceAreas;
    @service appCache;
    @service universe;

    @tracked routes = [];
    @tracked drivers = [];
    @tracked places = [];
    @tracked channels = [];
    @tracked isLoading = true;
    @tracked isReady = false;
    @tracked isDriversVisible = true;
    @tracked isPlacesVisible = true;
    @tracked isRoutesVisible = true;
    @tracked isDrawControlsVisible = false;
    @tracked isCreatingServiceArea = false;
    @tracked isCreatingZone = false;
    @tracked currentContextMenuItems = [];
    @tracked activeServiceAreas = [];
    @tracked editableLayers = [];
    @tracked leafletMap;
    @tracked activeFeatureGroup;
    @tracked drawFeatureGroup;
    @tracked drawControl;
    @tracked latitude = DEFAULT_LATITUDE;
    @tracked longitude = DEFAULT_LONGITUDE;
    @tracked skipSetCoordinates = false;
    @tracked mapId = guidFor(this);
    @alias('currentUser.latitude') userLatitude;
    @alias('currentUser.longitude') userLongitude;

    @computed('args.zoom') get zoom() {
        return this.args.zoom || 12;
    }

    @computed('args.{tileSourceUrl,darkMode}') get tileSourceUrl() {
        const { darkMode, tileSourceUrl } = this.args;

        if (darkMode === true) {
            return 'https://{s}.tile.jawg.io/jawg-matrix/{z}/{x}/{y}{r}.png?access-token=';
        }

        return tileSourceUrl ?? 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png';
    }

    /**
     * Creates an instance of LiveMapComponent.
     * @memberof LiveMapComponent
     */
    constructor() {
        super(...arguments);

        this.skipSetCoordinates = this.args.skipSetCoordinates ?? false;
    }

    /**
     * ----------------------------------------------------------------------------
     * SETUP
     * ----------------------------------------------------------------------------
     *
     * Functions for initialization and setup.
     */
    @action async setupLiveMap() {
        // trigger that initial coordinates have been set
        this.universe.trigger('livemap.loaded', this);

        if (this.skipSetCoordinates === false) {
            if (this.appCache.has(['map_latitude', 'map_longitude'])) {
                this.latitude = this.appCache.get('map_latitude');
                this.longitude = this.appCache.get('map_longitude');
                this.isReady = true;
            }

            const { latitude, longitude } = await this.getInitialCoordinates();

            if (this.appCache.doesntHave(['map_latitude', 'map_longitude'])) {
                this.appCache.set('map_latitude', latitude);
                this.appCache.set('map_longitude', longitude);
            }

            this.latitude = latitude;
            this.longitude = longitude;
        }

        // trigger that initial coordinates have been set
        this.universe.trigger('livemap.has_coordinates', { latitude: this.latitude, longitude: this.longitude });

        this.routes = await this.fetchActiveRoutes();
        this.drivers = await this.fetchActiveDrivers();
        this.places = await this.fetchActivePlaces();
        this.serviceAreaRecords = await this.fetchServiceAreas();
        this.isReady = true;

        this.watchDrivers(this.drivers);
        this.listenForOrders();

        if (typeof this.args.onReady === 'function') {
            this.args.onReady(this);
        }

        // add context event
        this.universe.trigger('livemap.ready', this);
    }

    @action setMapReference(event) {
        const { target } = event;

        // set liveMapComponent component to instance
        set(event, 'target.liveMap', this);

        // set map instance
        this.leafletMap = target;

        // setup context menu
        this.setupContextMenu(target);

        // hide draw controls by default
        this.hideDrawControls();

        // set instance to service areas service
        this.serviceAreas.setMapInstance(target);

        if (typeof this.args.onLoad === 'function') {
            this.args.onLoad(...arguments);
        }
    }

    @action setupContextMenu(map) {
        if (!map?.contextmenu) {
            return;
        }

        // reset items if any
        if (typeof map?.contextmenu?.removeAllItems === 'function') {
            map?.contextmenu?.removeAllItems();
        }

        const { contextmenu } = map;
        const contextMenuItems = this.buildContextMenuItems();

        contextMenuItems.forEach((options) => contextmenu.addItem(options));

        if (contextmenu.enabled === true || contextmenu._enabled === true) {
            return;
        }

        contextmenu.enable();
    }

    @action toggleDrawControlContextMenuItem() {
        const index = this.currentContextMenuItems.findIndex((options) => options.text?.includes('draw controls'));

        if (index > 0) {
            const options = this.currentContextMenuItems.objectAt(index);

            if (!isBlank(options)) {
                options.text = this.isDrawControlsVisible ? 'Hide draw controls...' : 'Enable draw controls...';
            }

            this.leafletMap?.contextmenu?.removeItem(index);
            this.leafletMap?.contextmenu?.insertItem(options, index);
        }
    }

    @action removeServiceAreaFromContextMenu(serviceArea) {
        const index = this.currentContextMenuItems.findIndex((options) => options.text?.includes(`Focus Service Area: ${serviceArea.name}`));

        if (index > 0) {
            this.leafletMap?.contextmenu?.removeItem(index);
        }
    }

    @action rebuildContextMenu() {
        const map = this.leafletMap;

        if (map) {
            this.setupContextMenu(map);
        }
    }

    @action setFn(actionName, callback) {
        this[actionName] = callback;
    }

    @action shouldSkipSettingInitialCoordinates() {
        this.skipSetCoordinates = true;
    }

    /**
     * ----------------------------------------------------------------------------
     * TRACKED LAYER UTILITIES
     * ----------------------------------------------------------------------------
     *
     * Functions provide utility for managing tracked editable layers.
     */
    @action pushEditableLayer(layer) {
        if (!this.editableLayers.includes(layer)) {
            this.editableLayers.pushObject(layer);
        }
    }

    @action removeEditableLayerByRecordId(record) {
        const index = this.editableLayers.findIndex((layer) => layer.record_id === record?.id ?? record);
        const layer = this.editableLayers.objectAt(index);

        this.drawFeatureGroup?.addLayer(layer);
        this.editableLayers.removeAt(index);
    }

    @action findEditableLayerByRecordId(record) {
        return this.editableLayers.find((layer) => layer.record_id === record?.id ?? record);
    }

    @action peekRecordForLayer(layer) {
        if (layer.record_id && layer.record_type) {
            return this.store.peekRecord(dasherize(layer.record_type), layer.record_id);
        }

        return null;
    }

    /**
     * ----------------------------------------------------------------------------
     * LAYER EVENTS
     * ----------------------------------------------------------------------------
     *
     * Functions that are only triggered from the `LiveMap` Leaflet Layer Component callbacks.
     */

    @action onAction(actionName, ...params) {
        if (typeof this[actionName] === 'function') {
            this[actionName](...params);
        }

        if (typeof this.args[actionName] === 'function') {
            this.args[actionName](...params);
        }
    }

    @action onDrawDrawstop(event, layer) {
        this.serviceAreas.createGenericLayer(event, layer);
    }

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

    @action onServiceAreaLayerAdded(serviceArea, event) {
        const { target } = event;

        set(target, 'record_id', serviceArea.id);
        set(target, 'record_type', 'service-area');

        // add to draw feature group
        this.drawFeatureGroup?.addLayer(target);

        // this.flyToBoundsOnly(target);
        this.createContextMenuForServiceArea(serviceArea, target);
        this.pushEditableLayer(target);
    }

    @action onZoneLayerAdd(zone, event) {
        const { target } = event;

        set(target, 'record_id', zone.id);
        set(target, 'record_type', 'zone');

        // add to draw feature group
        this.drawFeatureGroup?.addLayer(target);

        this.createContextMenuForZone(zone, target);
        this.pushEditableLayer(target);
    }

    @action onDrawFeatureGroupCreated(drawFeatureGroup) {
        this.drawFeatureGroup = drawFeatureGroup;
    }

    @action onDriverAdded(driver, event) {
        const { target } = event;

        // set the marker instance to the driver model
        set(driver, '_marker', target);

        console.log('onDriverAdded()', ...arguments);

        this.createContextMenuForDriver(driver, target);
    }

    @action onDrawControlCreated(drawControl) {
        this.drawControl = drawControl;
    }

    /**
     * ----------------------------------------------------------------------------
     * LEAFLET UTILITIES
     * ----------------------------------------------------------------------------
     *
     * Functions are used to help or utilize on Leaflet Layers/ Controls.
     *
     */
    @action removeDrawingControl() {
        if (isBlank(this.drawControl)) {
            return;
        }

        this.isDrawControlsVisible = false;
        this.leafletMap?.removeControl(this.drawControl);
        this.toggleDrawControlContextMenuItem();
    }

    // alias for `removeDrawingControl()`
    @action hideDrawControls() {
        this.removeDrawingControl();
    }

    @action enableDrawControls() {
        this.isDrawControlsVisible = true;
        this.leafletMap?.addControl(this.drawControl);
        this.toggleDrawControlContextMenuItem();
    }

    @action focusLayerByRecord(record) {
        const layer = this.findEditableLayerByRecordId(record);

        if (layer) {
            this.flyToBoundsOnly(layer);
        }
    }

    @action flyToServiceArea(serviceArea) {
        const layer = this.findEditableLayerByRecordId(serviceArea);

        if (layer) {
            this.flyToBoundsOnly(layer);
        }
    }

    // alias for `flyToServiceArea()`
    @action jumpToServiceArea(serviceArea) {
        return this.flyToServiceArea(serviceArea);
    }

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

    @action blurAllServiceAreas(except = []) {
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

    @action focusAllServiceAreas(except = []) {
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

    @action blurServiceArea(serviceArea) {
        if (this.activeServiceAreas.includes(serviceArea)) {
            this.activeServiceAreas.removeObject(serviceArea);
        }
    }

    @action activateServiceArea(serviceArea) {
        if (!this.activeServiceAreas.includes(serviceArea)) {
            this.activeServiceAreas.pushObject(serviceArea);
        }
    }

    @action hideDrivers() {
        this.isDriversVisible = false;
    }

    @action showDrivers() {
        this.isDriversVisible = true;
    }

    @action toggleDrivers() {
        this.isDriversVisible = !this.isDriversVisible;
    }

    @action hidePlaces() {
        this.isPlacesVisible = false;
    }

    @action showPlaces() {
        this.isPlacesVisible = true;
    }

    @action togglePlaces() {
        this.isPlacesVisible = !this.isPlacesVisible;
    }

    @action hideRoutes() {
        this.isRoutesVisible = false;
    }

    @action showRoutes() {
        this.isRoutesVisible = true;
    }

    @action toggleRoutes() {
        this.isRoutesVisible = !this.isRoutesVisible;
    }

    @action showCoordinates(event) {
        this.notifications.info(event.latlng);
    }

    @action centerMap(event) {
        this.leafletMap?.panTo(event.latlng);
    }

    @action zoomIn() {
        this.leafletMap?.zoomIn();
    }

    @action zoomOut() {
        this.leafletMap?.zoomOut();
    }

    @action setMaxBoundsFromLayer(layer) {
        const bounds = layer?.getBounds();

        this.leafletMap?.flyToBounds(bounds);
        this.leafletMap?.setMaxBounds(bounds);
    }

    @action flyToBoundsOnly(layer) {
        const bounds = layer?.getBounds();

        this.leafletMap?.flyToBounds(bounds);
    }

    /**
     * ----------------------------------------------------------------------------
     * CONTEXT MENU INITIALIZERS
     * ----------------------------------------------------------------------------
     *
     * Functions that are used to build context menu for layers and controls on the map.
     *
     */

    @action buildContextMenuItems() {
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
                text: this.isDrawControlsVisible ? `Hide draw controls...` : `Enable draw controls...`,
                callback: () => (this.isDrawControlsVisible ? this.hideDrawControls() : this.enableDrawControls()),
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

        // add service areas to context menu
        const serviceAreas = this.serviceAreas.getFromCache() ?? [];

        if (serviceAreas?.length > 0) {
            contextmenuItems.pushObject({
                separator: true,
            });
        }

        // add to context menu
        for (let i = 0; i < serviceAreas?.length; i++) {
            const serviceArea = serviceAreas.objectAt(i);

            contextmenuItems.pushObject({
                text: `Focus Service Area: ${serviceArea.name}`,
                callback: () => this.focusServiceArea(serviceArea),
                index: (contextmenuItems.lastObject?.index ?? 0) + 1 + i,
            });
        }

        this.currentContextMenuItems = contextmenuItems;

        return contextmenuItems;
    }

    @action createContextMenuForDriver(driver, layer) {
        const contextmenuItems = [
            {
                separator: true,
            },
            {
                text: `View Driver: ${driver.name}`,
                // callback: () => this.editServiceAreaDetails(serviceArea)
            },
            {
                text: `Edit Driver: ${driver.name}`,
                // callback: () => this.editServiceAreaDetails(serviceArea)
            },
            {
                text: `Delete Driver: ${driver.name}`,
                // callback: () => this.deleteServiceArea(serviceArea)
            },
            {
                text: `Assign Order to Driver: ${driver.name}`,
                // callback: () => this.deleteServiceArea(serviceArea)
            },
            {
                text: `View Vehicle for: ${driver.name}`,
                // callback: () => this.deleteServiceArea(serviceArea)
            },
        ];

        if (typeof layer?.bindContextMenu === 'function') {
            layer.bindContextMenu({
                contextmenu: true,
                contextmenuItems,
            });
        }
    }

    @action createContextMenuForZone(zone, layer) {
        const contextmenuItems = [
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

        if (typeof layer?.bindContextMenu === 'function') {
            layer.bindContextMenu({
                contextmenu: true,
                contextmenuItems,
            });
        }
    }

    @action createContextMenuForServiceArea(serviceArea, layer) {
        const contextmenuItems = [
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
                            this.rebuildContextMenu();
                            this.removeEditableLayerByRecordId(serviceArea);
                        },
                    }),
            },
        ];

        if (typeof layer?.bindContextMenu === 'function') {
            layer.bindContextMenu({
                contextmenu: true,
                contextmenuItems,
            });
        }
    }

    /**
     * ----------------------------------------------------------------------------
     * Async/Socket Functions
     * ----------------------------------------------------------------------------
     *
     * Functions are used to fetch date or handle socket callbacks/initializations.
     *
     */
    @action async listenForOrders() {
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

    @action watchDrivers(drivers = []) {
        // setup socket
        const socket = this.socket.instance();

        // listen for stivers
        for (let i = 0; i < drivers.length; i++) {
            const driver = drivers.objectAt(i);
            this.listenForDriver(driver, socket);
        }
    }

    @action async listenForDriver(driver, socket) {
        // listen on company channel
        const channelId = `driver.${driver.id}`;
        const channel = socket.subscribe(channelId);

        // track channel
        this.channels.pushObject(channel);

        // listen to channel for events
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
                console.log(`${event} - #${data.additionalData.index} (${output.created_at}) [ ${data.location.coordinates.join(' ')} ]`);
                // update driver heading degree
                driver._marker.setRotationAngle(data.heading);
                // move driver's marker to new coordinates
                driver._marker.slideTo(data.location.coordinates, { duration: 2000 });
            }

            // Clear the buffer
            eventBuffer.length = 0;
        };

        // Start a timer to process the buffer at intervals
        setInterval(processBuffer, bufferTime);

        // get incoming data and console out
        (async () => {
            for await (let output of channel) {
                const { event } = output;

                if (event === 'driver.location_changed' || event === 'driver.simulated_location_changed') {
                    // Add the incoming event to the buffer
                    eventBuffer.push(output);
                }
            }
        })();
    }

    @action closeChannels() {
        for (let i = 0; i < this.channels.length; i++) {
            const channel = this.channels.objectAt(i);

            channel.close();
        }
    }

    @action getInitialCoordinates() {
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

    @action fetchActiveRoutes() {
        this.isLoading = true;

        return new Promise((resolve) => {
            this.fetch
                .get('fleet-ops/live/routes')
                .then((routes) => {
                    this.isLoading = false;

                    if (typeof this.args.onRoutesLoaded === 'function') {
                        this.args.onRoutesLoaded(routes);
                    }

                    resolve(routes);
                })
                .catch(() => {
                    resolve([]);
                });
        });
    }

    @action fetchActiveDrivers() {
        this.isLoading = true;

        return new Promise((resolve) => {
            this.fetch
                .get('fleet-ops/live/drivers', {}, { normalizeToEmberData: true, normalizeModelType: 'driver' })
                .then((drivers) => {
                    this.isLoading = false;

                    if (typeof this.args.onDriversLoaded === 'function') {
                        this.args.onDriversLoaded(drivers);
                    }

                    resolve(drivers);
                })
                .catch(() => {
                    resolve([]);
                });
        });
    }

    @action fetchActivePlaces() {
        this.isLoading = true;

        // get the center of map
        const center = this.leafletMap.getCenter();

        return new Promise((resolve) => {
            this.fetch
                .get('fleet-ops/live/places', { within: center }, { normalizeToEmberData: true, normalizeModelType: 'place' })
                .then((places) => {
                    this.isLoading = false;

                    if (typeof this.args.onPlacesLoaded === 'function') {
                        this.args.onPlacesLoaded(places);
                    }

                    resolve(places);
                })
                .catch(() => {
                    resolve([]);
                });
        });
    }

    @action fetchServiceAreas() {
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

    @action getLocalLatitude() {
        const whois = this.currentUser.getOption('whois');
        const latitude = this.appCache.get('map_latitude');

        return latitude || whois?.latitude || DEFAULT_LATITUDE;
    }

    @action getLocalLongitude() {
        const whois = this.currentUser.getOption('whois');
        const longitude = this.appCache.get('map_longitude');

        return longitude || whois?.longitude || DEFAULT_LONGITUDE;
    }
}
