import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { dasherize } from '@ember/string';
import { later } from '@ember/runloop';
import GeoJson from '@fleetbase/fleetops-data/utils/geojson/geo-json';
import MultiPolygon from '@fleetbase/fleetops-data/utils/geojson/multi-polygon';
import Polygon from '@fleetbase/fleetops-data/utils/geojson/polygon';
import FeatureCollection from '@fleetbase/fleetops-data/utils/geojson/feature-collection';

export default class ServiceAreasService extends Service {
    @service store;
    @service modalsManager;
    @service notifications;
    @service crud;
    @service appCache;

    @tracked leafletMap;
    @tracked serviceAreaTypes = ['neighborhood', 'city', 'region', 'state', 'province', 'country', 'continent'];
    @tracked layerCreationContext;
    @tracked zoneServiceAreaContext;

    @action getFromCache() {
        return this.appCache.getEmberData('serviceAreas', 'service-area');
    }

    @action removeFromCache(serviceArea) {
        const serviceAreas = this.getFromCache();
        const index = serviceAreas?.findIndex((sa) => sa.id === serviceArea.id);

        if (index > 0) {
            const updatedServiceAreas = serviceAreas.removeAt(index);
            this.appCache.setEmberData('serviceAreas', updatedServiceAreas);
        }
    }

    @action addToCache(serviceArea) {
        const serviceAreas = this.getFromCache();

        if (isArray(serviceAreas)) {
            this.appCache.setEmberData('serviceAreas', [...serviceAreas, serviceArea]);
        } else {
            this.appCache.setEmberData('serviceAreas', [serviceArea]);
        }
    }

    @action layerToTerraformerPrimitive(layer) {
        const leafletLayerGeoJson = layer.toGeoJSON();
        let featureCollection, feature;

        if (leafletLayerGeoJson.type === 'FeatureCollection') {
            featureCollection = new FeatureCollection(leafletLayerGeoJson);
            feature = featureCollection.features.lastObject;
        } else if (leafletLayerGeoJson.type === 'Feature') {
            feature = leafletLayerGeoJson;
        }

        const primitive = new GeoJson(feature.geometry);

        return primitive;
    }

    @action layerToTerraformerMultiPolygon(layer) {
        const leafletLayerGeoJson = layer.toGeoJSON();
        let featureCollection, feature, coordinates;

        if (leafletLayerGeoJson.type === 'FeatureCollection') {
            featureCollection = new FeatureCollection(leafletLayerGeoJson);
            feature = featureCollection.features.lastObject;
        } else if (leafletLayerGeoJson.type === 'Feature') {
            feature = leafletLayerGeoJson;
        }

        coordinates = feature?.geometry?.coordinates ?? [];
        const multipolygon = new MultiPolygon([coordinates]);

        return multipolygon;
    }

    @action layerToTerraformerPolygon(layer) {
        const leafletLayerGeoJson = layer.toGeoJSON();
        let featureCollection, feature, coordinates;

        if (leafletLayerGeoJson.type === 'FeatureCollection') {
            featureCollection = new FeatureCollection(leafletLayerGeoJson);
            feature = featureCollection.features.lastObject;
        } else if (leafletLayerGeoJson.type === 'Feature') {
            feature = leafletLayerGeoJson;
        }

        coordinates = feature?.geometry?.coordinates ?? [];
        const polygon = new Polygon(coordinates);

        return polygon;
    }

    @action clearLayerCreationContext() {
        this.layerCreationContext = undefined;
    }

    @action setLayerCreationContext(context) {
        this.layerCreationContext = context;
    }

    @action clearZoneServiceAreaContext() {
        this.zoneServiceAreaContext = undefined;
    }

    @action setZoneServiceAreaContext(serviceArea) {
        this.zoneServiceAreaContext = serviceArea;
    }

    @action getZoneServiceAreaContext() {
        return this.zoneServiceAreaContext;
    }

    @action setMapInstance(map) {
        this.leafletMap = map;
    }

    @action sendToLiveMap(fn, ...params) {
        this.leafletMap?.liveMap[fn](...params);
    }

    @action createServiceArea() {
        this.sendToLiveMap('enableDrawControls');
        this.setLayerCreationContext('service-area');

        this.notifications.info('Use drawing controls to the right to draw a service area, complete point connections to save service area.', {
            clearDuration: 1000 * 9,
        });
    }

    @action createGenericLayer(event, layer, options = {}) {
        if (this.layerCreationContext === 'service-area') {
            return this.saveServiceArea(...arguments);
        }

        if (this.layerCreationContext === 'zone') {
            return this.saveZone(...arguments);
        }

        const { _map } = layer;
        const border = this.layerToTerraformerMultiPolygon(layer);

        if (!border) {
            return;
        }

        this.modalsManager.show('modals/map-layer-form', {
            title: 'Create new Layer',
            acceptButtonText: 'Create',
            acceptButtonIcon: 'magic',
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            layerTypes: ['Service Area', 'Zone'],
            selectedLayerType: 'Service Area',
            serviceAreaTypes: this.serviceAreaTypes,
            layerOptions: {},
            confirm: (modal) => {
                modal.startLoading();

                const selectedLayerType = modal.getOption('selectedLayerType');
                const layerOptions = modal.getOption('layerOptions');
                let serviceArea;

                // parse service area for zone
                if (selectedLayerType === 'Zone' && !layerOptions?.service_area) {
                    this.notifications.error('Service Area required to create Zone!');
                    return;
                } else {
                    serviceArea = layerOptions.service_area;
                }

                const record = this.store.createRecord(dasherize(selectedLayerType), layerOptions);
                record.setProperties({ border });

                return record.save().then((record) => {
                    this.notifications.success(`New ${selectedLayerType} '${record.name}' saved.`);

                    // remove drawn layer
                    _map?.removeLayer(layer);

                    // if service area has been created, add to the active service areas
                    if (selectedLayerType === 'Service Area') {
                        this.sendToLiveMap('activateServiceArea', record);
                        this.sendToLiveMap('focusLayerByRecord', record);
                    } else {
                        // if zone was created then we simply add the zone to the serviceArea selected
                        // then we focus the service area
                        serviceArea?.zones.pushObject(record);
                        this.sendToLiveMap('activateServiceArea', serviceArea);
                        this.sendToLiveMap('focusLayerByRecord', serviceArea);
                    }

                    // rebuild context menu
                    this.sendToLiveMap('rebuildContextMenu');
                    this.clearLayerCreationContext();
                });
            },
            decline: (modal) => {
                _map?.removeLayer(layer);
                modal.done();
            },
            ...options,
        });
    }

    @action saveServiceArea(event, layer) {
        const { _map } = layer;
        const border = this.layerToTerraformerMultiPolygon(layer);

        if (!border) {
            return;
        }

        const serviceArea = this.store.createRecord('service-area', {
            border,
            status: 'active',
        });

        return this.editServiceAreaDetails(serviceArea, {
            title: 'Save Service Area',
            acceptButtonText: 'Confirm & Save',
            onFinish: () => {
                _map?.removeLayer(layer);
            },
        });
    }

    @action editServiceAreaDetails(serviceArea, options = {}) {
        this.modalsManager.show('modals/service-area-form', {
            title: 'Edit Service Area',
            acceptButtonText: 'Save Changes',
            acceptButtonIcon: 'save',
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            serviceAreaTypes: this.serviceAreaTypes,
            serviceArea,
            confirm: (modal) => {
                modal.startLoading();

                return serviceArea.save().then((serviceArea) => {
                    this.notifications.success(`New service area '${serviceArea.name}' saved.`);

                    this.clearLayerCreationContext();
                    this.addToCache(serviceArea);
                    this.sendToLiveMap('focusServiceArea', serviceArea);
                    // this.sendToLiveMap('rebuildContextMenu');
                });
            },
            decline: (modal) => {
                this.clearLayerCreationContext();
                this.sendToLiveMap('hideDrawControls');

                if (serviceArea.isNew) {
                    serviceArea.destroyRecord();
                }
                modal.done();
            },
            ...options,
        });
    }

    @action deleteServiceArea(serviceArea, options = {}) {
        this.sendToLiveMap('focusLayerByRecord', serviceArea);

        this.crud.delete(serviceArea, {
            onConfirm: () => {
                this.sendToLiveMap('blurServiceArea', serviceArea);
                this.removeFromCache(serviceArea);
            },
            ...options,
        });
    }

    @action createZone(serviceArea) {
        this.sendToLiveMap('enableDrawControls');
        this.sendToLiveMap('focusServiceArea', serviceArea);
        this.setZoneServiceAreaContext(serviceArea);
        this.setLayerCreationContext('zone');

        this.notifications.info('Use the drawing controls to the right to draw a zone within the service area, complete point connections to save the zone.', {
            clearDuration: 1000 * 9,
        });
    }

    @action saveZone(event, layer) {
        const { _map } = layer;
        const border = this.layerToTerraformerPolygon(layer);
        const serviceArea = this.getZoneServiceAreaContext();

        const zone = this.store.createRecord('zone', {
            service_area_uuid: serviceArea.id,
            serviceArea,
            border,
        });

        return this.editZone(zone, serviceArea, {
            title: 'Save Zone',
            acceptButtonText: 'Confirm & Save',
            onFinish: () => {
                _map?.removeLayer(layer);
            },
        });
    }

    @action editZone(zone, serviceArea, options = {}) {
        this.modalsManager.show('modals/zone-form', {
            title: 'Edit Zone',
            acceptButtonText: 'Save Changes',
            acceptButtonIcon: 'save',
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            zone,
            confirm: (modal) => {
                modal.startLoading();

                return zone.save().then(() => {
                    this.notifications.success(`New zone '${zone.name}' added to '${serviceArea.name}' service area.`);

                    this.clearLayerCreationContext();
                    this.clearZoneServiceAreaContext();
                    this.sendToLiveMap('hideDrawControls');
                    this.sendToLiveMap('blurAllServiceAreas');

                    later(
                        this,
                        () => {
                            this.sendToLiveMap('focusServiceArea', serviceArea);
                        },
                        300
                    );
                    // this.sendToLiveMap('rebuildContextMenu');
                });
            },
            decline: (modal) => {
                this.clearLayerCreationContext();
                this.clearZoneServiceAreaContext();
                this.sendToLiveMap('hideDrawControls');

                if (zone.isNew) {
                    zone.destroyRecord();
                }
                modal.done();
            },
            ...options,
        });
    }

    @action deleteZone(zone, options = {}) {
        this.crud.delete(zone, {
            ...options,
        });
    }

    @action viewServiceAreaInDialog(serviceArea, options = {}) {
        this.modalsManager.show('modals/view-service-area', {
            title: `Service Area (${serviceArea.get('name')})`,
            modalClass: 'modal-lg',
            acceptButtonText: 'Done',
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            hideDeclineButton: true,
            serviceArea,
            ...options,
        });
    }

    @action viewZoneInDialog(zone, options = {}) {
        this.modalsManager.show('modals/view-zone', {
            title: `Zone (${zone.get('name')})`,
            modalClass: 'modal-lg',
            acceptButtonText: 'Done',
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            hideDeclineButton: true,
            zone,
            ...options,
        });
    }
}
