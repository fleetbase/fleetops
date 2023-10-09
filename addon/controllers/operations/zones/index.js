import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { later } from '@ember/runloop';
import GeoJson from '@fleetbase/fleetops-data/utils/geojson/geo-json';
import FeatureCollection from '@fleetbase/fleetops-data/utils/geojson/feature-collection';
import last from '@fleetbase/ember-core/utils/last';

export default class OperationsZonesIndexController extends Controller {
    /**
     * Inject the `currentUser` service
     *
     * @var {Service}
     */
    @service store;

    /**
     * Inject the `currentUser` service
     *
     * @var {Service}
     */
    @service currentUser;

    /**
     * Inject the `modalsManager` service
     *
     * @var {Service}
     */
    @service modalsManager;

    /**
     * Inject the `notifications` service
     *
     * @var {Service}
     */
    @service notifications;

    /**
     * Inject the `hostRouter` service
     *
     * @var {Service}
     */
    @service hostRouter;

    /**
     * Inject the `crud` service
     *
     * @var {Service}
     */
    @service crud;

    /**
     * True if the viewing an orders route preview.
     *
     * @var {L.Map}
     */
    @tracked leafletMap;

    /**
     * The ActiveFeatureGroup
     *
     * @var {ActiveFeatureGroup}
     */
    @tracked activeFeatureGroup;

    /**
     * All active editable zone layers
     *
     * @var {Array}
     */
    @tracked editableZones = [];

    /**
     * True to display draw controls on map.
     *
     * @var {Boolean}
     */
    @tracked showDrawControl;

    /**
     * The active service area to focus.
     *
     * @var {ServiceAreaModel}
     */
    @tracked activeServiceArea;

    /**
     * The users latitude based on whois lookup.
     *
     * @var {String}
     */
    get userLatitude() {
        const whois = this.currentUser.getOption('whois');

        return whois.latitude || null;
    }

    /**
     * The users longitude based on whois lookup.
     *
     * @var {String}
     */
    get userLongitude() {
        const whois = this.currentUser.getOption('whois');

        return whois.longitude || null;
    }

    /**
     * Sets a reference to the leaflet map onload
     *
     * @void
     */
    @action
    setMapReference(event) {
        if (!event || !event.target) {
            return;
        }

        this.leafletMap = event.target;

        // handle resize event now
        later(
            this,
            () => {
                this.leafletMap.invalidateSize();
            },
            100
        );
    }

    /**
     * Set the active feature group
     *
     * @param {ActiveFeatureGroup} activeFeatureGroup
     * @param {LeafletMap} map
     * @void
     */
    @action
    setFeatureGroup(activeFeatureGroup, map) {
        this.activeFeatureGroup = activeFeatureGroup;
        this.leafletMap = map;
    }

    /**
     * Enable zone editing for the passed ServiceArea.
     *
     * @param {ServiceAreaModel} serviceArea
     */
    @action
    startZoneEditing(serviceArea) {
        this.activeServiceArea = serviceArea;
        this.showDrawControl = true;

        let bounds = serviceArea.bounds;

        this.leafletMap.setMaxBounds(bounds);
        this.leafletMap.flyToBounds(bounds);
        this.notifications.info('Use the draw controls to the left to define, edit, or delete zones within your service area, your zone is not limited to the bounds of your service area.');
    }

    /**
     * Adds a new layer to the ActiveFeatureGroup
     *
     * @param {ZoneModel} zone
     * @void
     */
    @action
    makeZoneEditable(zone, { target }) {
        if (this.editableZones.includes(target) || !this.activeFeatureGroup || this.activeFeatureGroup.hasLayer(target)) {
            return;
        }

        target._zone_id = zone.id || 'new_zone';

        this.editableZones.pushObject(target);
        this.activeFeatureGroup.addLayer(target);
    }

    /**
     * Save changes made to zone coordinates.
     *
     * @param {Event} event
     * @void
     */
    @action
    saveZoneCoordinateChanges(event, _layer) {
        const serviceArea = this.activeServiceArea;

        if (!serviceArea) {
            return;
        }

        // get all layers
        const layers = Object.values(_layer._layers);

        // iterate and get zone model
        for (let i = 0; i < layers.length; i++) {
            const layer = layers[i];

            if (!layer._boundary_id) {
                continue;
            }

            // peek boundary to destroy
            const zone = this.store.peekRecord('zone', layer._zone_id);

            if (zone) {
                const geoJson = layer.toGeoJSON();
                const border = new GeoJson(geoJson.geometry);

                zone.border = border;
                zone.save();
            }
        }
    }

    /**
     * Delete boundaries selected in layers
     *
     * @param {Event} event
     * @void
     */
    @action
    deleteZoneBorders({ layers }) {
        const serviceArea = this.activeServiceArea;

        if (!serviceArea) {
            return;
        }

        // get current zones for service area
        const zones = serviceArea.zones || [];

        // get array of layers to delete
        layers = Array.from(layers.getLayers());

        // iterate and get zone model
        for (let i = 0; i < layers.length; i++) {
            const layer = layers[i];

            if (!layer._zone_id) {
                continue;
            }

            // peek zone to destroy
            const zone = this.store.peekRecord('boundary', layer._zone_id);

            if (zone) {
                zones.removeObject(zone);
                zone.destroyRecord();
            }
        }
    }

    @action
    createZoneFromLayer(event, layer) {
        const serviceArea = this.activeServiceArea;

        if (!serviceArea) {
            return;
        }

        const leafletLayerGeoJson = layer.toGeoJSON();
        const featureCollection = new FeatureCollection(leafletLayerGeoJson);
        const feature = last(featureCollection.features);
        const border = new GeoJson(feature.geometry);

        return this.createZone({
            service_area_uuid: serviceArea.id,
            border,
        });
    }

    /**
     * Create a new `zone` in modal
     *
     * @param {Object} options
     * @void
     */
    @action
    createZone(attrs = {}) {
        const zone = this.store.createRecord('zone', attrs);

        return this.editZone(zone, {
            title: 'New Zone',
            acceptButtonText: 'Confirm & Create',
            successNotification: (zone) => `New zone (${zone.name}) successfully created.`,
            onConfirm: () => {
                // make sure to push zone to serviceArea model
                let serviceArea = this.store.peekRecord('service-area', zone.service_area_uuid);

                if (serviceArea) {
                    serviceArea.zones.pushObject(zone);
                }
            },
        });
    }

    /**
     * Edit a `zone` details
     *
     * @param {ZoneModel} zone
     * @param {Object} options
     * @void
     */
    @action
    editZone(zone, options = {}) {
        this.modalsManager.show('modals/zone-form', {
            title: 'Edit Zone',
            acceptButtonText: 'Save Changes',
            zone,
            confirm: (modal) => {
                modal.startLoading();

                return zone.save().then((zone) => {
                    if (typeof options.successNotification === 'function') {
                        this.notifications.success(options.successNotification(zone));
                    } else {
                        this.notifications.success(options.successNotification || `${zone.name} details updated.`);
                    }
                });
            },
            ...options,
        });
    }

    /**
     * Delete a `zone` via confirm prompt
     *
     * @param {ZoneModel} zone
     * @param {Object} options
     * @void
     */
    @action
    deleteZone(zone, options = {}) {
        this.crud.delete(zone, {
            ...options,
        });
    }

    /**
     * Edit a `zone` border coordinates.
     *
     * @param {ZoneModel} zone
     * @param {Object} options
     * @void
     */
    @action
    editZoneBorder() {}

    /**
     * Create a new `serviceArea` in modal
     *
     * @param {Object} options
     * @void
     */
    @action
    createServiceArea() {
        const serviceArea = this.store.createRecord('service-area', {
            status: 'active',
            type: 'country',
        });

        return this.editServiceArea(serviceArea, {
            title: 'New Service Area',
            acceptButtonText: 'Confirm & Create',
            successNotification: (serviceArea) => `New service area (${serviceArea.name}) created.`,
            onConfirm: () => {
                this.hostRouter.refresh();
            },
        });
    }

    /**
     * Edit a `serviceArea` details
     *
     * @param {ServiceAreaModel} serviceArea
     * @param {Object} options
     * @void
     */
    @action
    editServiceArea(serviceArea, options = {}) {
        this.modalsManager.show('modals/service-area-form', {
            title: 'Edit Service Area',
            acceptButtonText: 'Save Changes',
            serviceArea,
            confirm: (modal) => {
                modal.startLoading();

                return serviceArea.save().then((serviceArea) => {
                    if (typeof options.successNotification === 'function') {
                        this.notifications.success(options.successNotification(serviceArea));
                    } else {
                        this.notifications.success(options.successNotification || `${serviceArea.name} details updated.`);
                    }
                });
            },
            ...options,
        });
    }

    /**
     * Rename a `serviceArea` details
     *
     * @param {ServiceAreaModel} serviceArea
     * @param {Object} options
     * @void
     */
    @action
    renameServiceArea(serviceArea, options = {}) {
        this.modalsManager.show('modals/service-area-form', {
            title: 'Rename Service Area',
            acceptButtonText: 'Save Changes',
            rename: true,
            serviceArea,
            confirm: (modal) => {
                modal.startLoading();

                return serviceArea.save().then((serviceArea) => {
                    if (typeof options.successNotification === 'function') {
                        this.notifications.success(options.successNotification(serviceArea));
                    } else {
                        this.notifications.success(options.successNotification || `${serviceArea.name} renamed.`);
                    }
                });
            },
            ...options,
        });
    }

    /**
     * Delete a `serviceArea` via confirm prompt
     *
     * @param {ServiceAreaModel} serviceArea
     * @param {Object} options
     * @void
     */
    @action
    deleteServiceArea(serviceArea, options = {}) {
        // if the service area being deleted is active untoggle
        if (serviceArea === this.activeServiceArea) {
            this.activeServiceArea = null;
        }

        this.crud.delete(serviceArea, {
            ...options,
        });
    }

    @action moveToCurrentLocation() {
        // eslint-disable-next-line no-undef
        if (window.navigator && window.navigator.geolocation) {
            // eslint-disable-next-line no-undef
            return navigator.geolocation.getCurrentPosition(
                ({ coords }) => {
                    const { latitude, longitude } = coords;

                    this.leafletMap.flyTo([latitude, longitude]);
                },
                () => {
                    this.notifications.error('Unable to move to current location.');
                }
            );
        }
    }

    @action handleMapResize(options = {}, container) {
        const element = this.leafletMap._container;

        element.style.width = `${container.offsetWidth - (options.horizontalLeftPadding || 0) - (options.horizontalRightPadding || 0)}px`;
        element.style.height = `${container.offsetHeight - (options.verticalTopPadding || 0) - (options.verticalBottomPadding || 0)}px`;
    }
}
