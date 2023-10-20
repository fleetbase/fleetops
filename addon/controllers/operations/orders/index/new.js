import Controller, { inject as controller } from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action, computed, setProperties, set, get } from '@ember/object';
import { not, equal, alias } from '@ember/object/computed';
import { isArray } from '@ember/array';
import { dasherize } from '@ember/string';
import { later, next } from '@ember/runloop';
import { OSRMv1, Control as RoutingControl } from '@fleetbase/leaflet-routing-machine';
import polyline from '@fleetbase/ember-core/utils/polyline';
import findClosestWaypoint from '@fleetbase/ember-core/utils/find-closest-waypoint';
import isNotEmpty from '@fleetbase/ember-core/utils/is-not-empty';
import getRoutingHost from '@fleetbase/ember-core/utils/get-routing-host';
import groupBy from '@fleetbase/ember-core/utils/macros/group-by';
import extractCoordinates from '@fleetbase/ember-core/utils/extract-coordinates';

L.Bounds.prototype.intersects = function (bounds) {
    var min = this.min,
        max = this.max,
        min2 = bounds.min,
        max2 = bounds.max,
        xIntersects = max2.x >= min.x && min2.x <= max.x,
        yIntersects = max2.y >= min.y && min2.y <= max.y;

    return xIntersects && yIntersects;
};

export default class OperationsOrdersIndexNewController extends Controller {
    @controller('management.places.index') placesController;
    @controller('management.contacts.index') contactsController;
    @controller('management.vendors.index') vendorsController;
    @controller('operations.orders.index') ordersController;

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
     * Inject the `loader` service
     *
     * @var {Service}
     */
    @service loader;

    /**
     * Inject the `currentUser` service
     *
     * @var {Service}
     */
    @service currentUser;

    /**
     * Inject the `hostRouter` service
     *
     * @var {Service}
     */
    @service hostRouter;

    /**
     * Inject the `fileQueue` service
     *
     * @var {Service}
     */
    @service fileQueue;

    /**
     * Inject the `fetch` service
     *
     * @var {Service}
     */
    @service fetch;

    /**
     * Inject the `store` service
     *
     * @var {Service}
     */
    @service store;

    /**
     * Inject the `universe` service
     *
     * @var {Service}
     */
    @service universe;

    /**
     * Create an OrderModel instance.
     *
     * @var {OrderModel}
     */
    @tracked order = this.store.createRecord('order', { meta: [] });

    /**
     * Create an PayloadModel instance.
     *
     * @var {OrderModel}
     */
    @tracked payload = this.store.createRecord('payload');
    @tracked driversQuery = {};
    @tracked meta = [];
    @tracked entities = [];
    @tracked waypoints = [];
    @tracked types = [];
    @tracked serviceRates = [];
    @tracked selectedServiceRate;
    @tracked selectedServiceQuote;
    @tracked isCreatingOrder = false;
    @tracked isMultipleDropoffOrder = false;
    @tracked isViewingRoutePreview = false;
    @tracked isOptimizingRoute = false;
    @tracked optimizedRouteMarkers = [];
    @tracked optimizedRoutePolyline;
    @tracked isFetchingQuotes = false;
    @tracked servicable = false;
    @tracked scheduledDate;
    @tracked scheduledTime;
    @tracked leafletRoute;
    @tracked leafletOptimizedRoute;
    @tracked currentLeafletRoute;
    @tracked leafletLayers = [];
    @tracked routeProfile = 'driving';
    @tracked routeProfileOptions = ['driving', 'bycicle', 'walking'];
    @tracked podOptions = ['scan', 'signature'];
    @tracked isViewingRoutePreview = false;
    @tracked isCsvImportedOrder = false;
    @tracked routePreviewArray = [];
    @tracked previewRouteControl;
    @tracked isSubscriptionValid = true;
    @tracked isUsingIntegratedVendor = false;
    @tracked integratedVendorServiceType;
    @tracked invalidReason;
    @tracked metadataButtons = [
        {
            type: 'default',
            text: 'Edit metadata',
            icon: 'edit',
            onClick: this.editMetaData,
        },
    ];

    @not('isServicable') isNotServicable;
    @alias('currentUser.latitude') userLatitude;
    @alias('currentUser.longitude') userLongitude;
    @alias('ordersController.leafletMap') leafletMap;
    @equal('isCsvImportedOrder', false) isNotCsvImportedOrder;
    @groupBy('typeConfig.meta.fields', 'group') groupedMetaFields;

    @computed('entities.length', 'isMultipleDropoffOrder', 'isFetchingQuotes', 'isSubscriptionValid', 'order.type', 'payload.{dropoff,pickup}', 'waypoints.length')
    get isValid() {
        const { isMultipleDropoffOrder, isSubscriptionValid, isFetchingQuotes } = this;
        const isOrderTypeSet = isNotEmpty(this.order?.type);
        const isWaypointsSet = this.waypoints?.length > 1;
        const isPickupSet = isNotEmpty(this.payload?.pickup);
        const isDropoffSet = isNotEmpty(this.payload?.dropoff);
        // const isPayloadSet = this.entities?.length > 0;

        if (isFetchingQuotes) {
            return false;
        }

        if (!isSubscriptionValid) {
            return false;
        }

        if (isMultipleDropoffOrder) {
            return isOrderTypeSet && isWaypointsSet;
        }

        return isOrderTypeSet && isPickupSet && isDropoffSet;
    }

    @computed('payload.{dropoff,pickup,waypoints}', 'waypoints.[]')
    get payloadCoordinates() {
        let waypoints = [];
        let coordinates = [];

        waypoints.pushObjects([this.payload.pickup, ...this.waypoints.map((waypoint) => waypoint.place), this.payload.dropoff]);
        waypoints.forEach((place) => {
            if (place && place.get('longitude') && place.get('latitude')) {
                if (place.hasInvalidCoordinates) {
                    return;
                }

                coordinates.pushObject([place.get('longitude'), place.get('latitude')]);
            }
        });

        return coordinates;
    }

    @computed('payloadCoordinates.length', 'waypoints.[]') get isServicable() {
        return this.payloadCoordinates.length >= 2;
    }

    @computed('routePreviewArray.[]') get routePreviewCoordinates() {
        // return this.routePreviewArray.filter((place) => place.get('hasValidCoordinates')).map((place) => place.get('latlng'));
        return (
            this.routePreviewArray
                // .filter((place) => place.get('hasValidCoordinates'))
                .map((place) => place.get('extractedLatLng'))
        );
    }

    @computed('order.type', 'types') get typeConfig() {
        return this.types.find((type) => type.key === this.order.type);
    }

    @computed('order.type', 'types') get typeConfigIndex() {
        return this.types.findIndex((type) => type.key === this.order.type);
    }

    @computed('entities.[]', 'waypoints.[]') get entitiesByImportId() {
        const groups = [];

        // create groups
        this.waypoints.forEach((waypoint) => {
            const importId = waypoint.place._import_id ?? null;

            if (importId) {
                const entities = this.entities.filter((entity) => entity._import_id === importId);

                // if (entities.length === 0) {
                //     return;
                // }

                const group = {
                    importId,
                    waypoint,
                    entities,
                };

                groups.pushObject(group);
            }
        });

        return groups;
    }

    @action createOrder() {
        if (!this.isValid) {
            return;
        }

        this.previewRoute(false);
        this.loader.showLoader('body', { loadingMessage: 'Creating Order...' });

        const { order, groupedMetaFields, payload, entities, waypoints, isMultipleDropoffOrder } = this;
        const route = this.leafletOptimizedRoute ? this.getOptimizedRoute() : this.getRoute();

        // set service quote if applicable
        if (this.selectedServiceQuote) {
            order.service_quote_uuid = this.selectedServiceQuote;
        }

        try {
            order
                .serializeMeta()
                .serializeMetaFromGroupedFields(groupedMetaFields)
                .setPayload(payload)
                .setRoute(route)
                .get('payload')
                .setWaypoints(waypoints, isMultipleDropoffOrder)
                .setEntities(entities);
        } catch (error) {
            this.notifications.serverError(error);
            this.loader.removeLoader();
            return;
        }

        // send event that fleetops is `creating` an order
        this.universe.trigger('fleet-ops.order.creating', order);
        this.isCreatingOrder = true;

        try {
            return order
                .save()
                .then((order) => {
                    // trigger event that fleet-ops created an order
                    this.universe.trigger('fleet-ops.order.created', order);

                    // transition to order view
                    return this.transitionToRoute('operations.orders.index.view', order).then(() => {
                        this.notifications.success(`New Order ${order.public_id} Created`);
                        this.loader.removeLoader();
                        this.resetForm();
                        later(
                            this,
                            () => {
                                this.hostRouter.refresh();
                            },
                            100
                        );
                    });
                })
                .catch((error) => {
                    this.isCreatingOrder = false;
                    this.notifications.serverError(error);
                    this.loader.removeLoader();
                });
        } catch (error) {
            this.notifications.error(error.message);
            this.loader.removeLoader();
        }
    }

    @action importOrder() {
        const checkQueue = () => {
            const uploadQueue = this.modalsManager.getOption('uploadQueue');

            if (uploadQueue.length) {
                this.modalsManager.setOption('acceptButtonDisabled', false);
            } else {
                this.modalsManager.setOption('acceptButtonDisabled', true);
            }
        };

        this.modalsManager.show('modals/order-import', {
            title: 'Import order(s) with spreadsheets',
            acceptButtonText: 'Start Upload',
            acceptButtonScheme: 'magic',
            acceptButtonIcon: 'upload',
            acceptButtonDisabled: true,
            isProcessing: false,
            uploadQueue: [],
            fileQueueColumns: [
                { name: 'Type', valuePath: 'extension', key: 'type' },
                { name: 'File Name', valuePath: 'name', key: 'fileName' },
                { name: 'File Size', valuePath: 'size', key: 'fileSize' },
                {
                    name: 'Upload Date',
                    valuePath: 'blob.lastModifiedDate',
                    key: 'uploadDate',
                },
                { name: '', valuePath: '', key: 'delete' },
            ],
            acceptedFileTypes: ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv'],
            queueFile: (file) => {
                const uploadQueue = this.modalsManager.getOption('uploadQueue');

                uploadQueue.pushObject(file);
                checkQueue();
            },
            removeFile: (file) => {
                const { queue } = file;
                const uploadQueue = this.modalsManager.getOption('uploadQueue');

                uploadQueue.removeObject(file);
                queue.remove(file);
                checkQueue();
            },
            confirm: async (modal) => {
                const uploadQueue = this.modalsManager.getOption('uploadQueue');
                const uploadedFiles = [];
                const uploadTask = (file) => {
                    return new Promise((resolve) => {
                        this.fetch.uploadFile.perform(
                            file,
                            {
                                path: `uploads/order-imports/${this.currentUser.companyId}`,
                                type: `order_import`,
                            },
                            (uploadedFile) => {
                                uploadedFiles.pushObject(uploadedFile);

                                resolve(uploadedFile);
                            }
                        );
                    });
                };

                if (!uploadQueue.length) {
                    return this.notifications.warning('No files in queue to upload!');
                }

                modal.startLoading();
                modal.setOption('acceptButtonText', 'Uploading...');

                for (let i = 0; i < uploadQueue.length; i++) {
                    const file = uploadQueue.objectAt(i);

                    await uploadTask(file);
                }

                this.modalsManager.setOption('acceptButtonText', 'Processing...');
                this.modalsManager.setOption('isProcessing', true);

                const files = uploadedFiles.map((file) => file.id);

                let results;

                try {
                    results = await this.fetch.post('orders/process-imports', { files });
                } catch (error) {
                    return this.notifications.serverError(error);
                }

                // import places
                if (isArray(results?.places)) {
                    this.isMultipleDropoffOrder = true;

                    // map into place models
                    const waypoints = results.places.map((_place) => {
                        const place = this.store.createRecord('place', _place);

                        return this.store.createRecord('waypoint', { place });
                    });

                    this.waypoints = waypoints;
                }

                // import entities
                if (isArray(results?.entities)) {
                    // map into entity models
                    const entities = results.entities.map((entity) => {
                        return this.store.createRecord('entity', entity);
                    });

                    this.entities = entities;
                }

                this.notifications.success('Import completed.');
                this.isCsvImportedOrder = true;
                this.previewDraftOrderRoute(this.payload, this.waypoints, this.isMultipleDropoffOrder);
                modal.done();
            },
            decline: (modal) => {
                this.modalsManager.setOption('uploadQueue', []);
                this.fileQueue.flush();

                modal.done();
            },
        });
    }

    @action async toggleAdhoc(on) {
        const defaultDistanceInMeters = 5000;

        if (on) {
            const company = this.store.peekRecord('company', this.currentUser.companyId);
            this.order.adhoc_distance = company?.options?.fleetops?.adhoc_distance ?? defaultDistanceInMeters;
        } else {
            this.order.adhoc_distance = defaultDistanceInMeters;
        }

        this.order.adhoc = on;
    }

    @action async toggleProofOfDelivery(on) {
        this.order.pod_required = on;

        if (on) {
            this.order.pod_method = 'scan';
        } else {
            this.order.pod_method = null;
        }
    }

    @action async checkServiceRates(shouldCheck) {
        this.servicable = shouldCheck;
        const params = {
            coordinates: this.getCoordinatesFromPayload().join(';'),
        };
        let serviceRates = [];

        if (this.isUsingIntegratedVendor) {
            params.facilitator = this.order.facilitator.public_id;
        }

        if (shouldCheck) {
            try {
                serviceRates = await this.fetch.get(`service-rates/for-route`, params);

                serviceRates.unshiftObject({
                    service_name: 'Quote from all Service Rates',
                    id: 'all',
                });
            } catch (error) {
                this.notifications.serverError(error);
            }

            this.serviceRates = serviceRates;
        }
    }

    @action newPlace() {
        if (this.placesController) {
            return this.placesController.createPlace();
        }
    }

    @action async getQuotes(service) {
        this.isFetchingQuotes = true;

        let payload = this.payload.serialize();
        let route = this.getRoute();
        let distance = get(route, 'payload.summary.totalDistance');
        let time = get(route, 'payload.summary.totalTime');
        let service_type = this.order.type;
        let scheduled_at = this.order.scheduled_at;
        let facilitator = this.order.facilitator?.get('public_id');
        let is_route_optimized = this.order.get('is_route_optimized');
        let { waypoints, entities } = this;
        let places = [];

        if (this.payloadCoordinates?.length < 2) {
            this.isFetchingQuotes = false;
            return;
        }

        if (this.isUsingIntegratedVendor && this.integratedVendorServiceType) {
            service_type = this.integratedVendorServiceType;
        }

        // get place instances from WaypointModel
        for (let i = 0; i < waypoints.length; i++) {
            let place = await waypoints[i].place;

            places.pushObject(place);
        }

        setProperties(payload, { waypoints: places, entities });

        if (!payload.type && this.order.type) {
            setProperties(payload, { type: this.order.type });
        }

        this.fetch
            .post('service-quotes/preliminary', {
                payload,
                distance,
                time,
                service,
                service_type,
                facilitator,
                scheduled_at,
                is_route_optimized,
            })
            .then((serviceQuotes) => {
                set(this, 'serviceQuotes', isArray(serviceQuotes) ? serviceQuotes : []);

                if (this.serviceQuotes.length && this.isUsingIntegratedVendor) {
                    set(this, 'selectedServiceQuote', this.serviceQuotes.firstObject?.uuid);
                }
            })
            .catch(() => {
                this.notifications.warning('Failed to fetch service quotes for this order.');
            })
            .finally(() => {
                this.isFetchingQuotes = false;
            });
    }

    @action scheduleOrder(dateInstance) {
        this.order.scheduled_at = dateInstance;
    }

    @action setupInterface() {
        if (this.leafletMap && this.leafletMap.liveMap) {
            this.leafletMap.liveMap.hideAll();

            // track all layers added from this view
            this.leafletMap.on('layeradd', ({ layer }) => {
                // disable dragging of layer
                if (layer.dragging && typeof layer.dragging.disable === 'function') {
                    layer.dragging.disable();
                }

                next(this, function () {
                    if (isArray(this.leafletLayers) && !this.leafletLayers.includes(layer)) {
                        this.leafletLayers.pushObject(layer);
                    }
                });
            });
        } else {
            // setup interface when livemap is ready
            this.universe.on('fleetops.livemap.ready', () => {
                this.setupInterface();
            });
        }

        // switch to map mode
        this.ordersController.setLayoutMode('map');
    }

    @action resetInterface() {
        if (this.leafletMap && this.leafletMap.liveMap) {
            this.leafletMap.liveMap.show(['drivers', 'vehicles', 'routes']);
        }
    }

    @action getRoute() {
        const details = this.leafletRoute;
        const route = this.store.createRecord('route', { details });

        return route;
    }

    @action getOptimizedRoute() {
        const details = this.leafletOptimizedRoute;
        const route = this.store.createRecord('route', { details });

        return route;
    }

    @action setOptimizedRoute(route, trip, waypoints) {
        let summary = { totalDistance: trip.distance, totalTime: trip.duration };
        let payload = {
            optimized: true,
            coordinates: route,
            waypoints,
            trip,
            summary,
        };

        this.leafletOptimizedRoute = payload;
    }

    @action removeRoutingControlPreview() {
        const leafletMap = this.leafletMap;
        const previewRouteControl = this.previewRouteControl;

        let removed = false;

        if (leafletMap && previewRouteControl instanceof RoutingControl) {
            try {
                previewRouteControl.remove();
                removed = true;
            } catch (e) {
                // silent
            }

            if (!removed) {
                try {
                    leafletMap.removeControl(previewRouteControl);
                } catch (e) {
                    // silent
                }
            }
        }

        if (!removed) {
            this.forceRemoveRoutePreview();
        }
    }

    @action forceRemoveRoutePreview() {
        const { leafletMap } = this;

        leafletMap.eachLayer((layer) => {
            if (layer instanceof L.Polyline || layer instanceof L.Marker) {
                try {
                    layer.remove();
                } catch (error) {
                    // silent error just continue with order processing if any
                }
            }
        });
    }

    @action removePreviewRouteLayers() {
        const { currentLeafletRoute, leafletMap } = this;

        if (currentLeafletRoute) {
            // target is the route, and waypoints is the markers
            const { target, waypoints } = currentLeafletRoute;

            leafletMap.removeLayer(target);
            waypoints?.forEach((waypoint) => {
                try {
                    leafletMap.removeLayer(waypoint);
                } catch (error) {
                    // silent error just continue with order processing if any
                }
            });
        }
    }

    @action clearLayers() {
        if (this.leafletMap) {
            try {
                this.leafletMap.eachLayer((layer) => {
                    if (isArray(this.leafletLayers) && this.leafletLayers.includes(layer)) {
                        this.leafletMap.removeLayer(layer);
                    }
                });
            } catch (error) {
                // fallback method with tracked layers
                if (isArray(this.leafletLayers)) {
                    this.leafletLayers.forEach((layer) => {
                        try {
                            this.leafletMap.removeLayer(layer);
                        } catch (error) {
                            // silent error just continue with order processing if any
                        }
                    });
                }
            }
        }
    }

    @action clearAllLayers() {
        if (this.leafletMap) {
            try {
                this.leafletMap.eachLayer((layer) => {
                    this.leafletMap.removeLayer(layer);
                });
            } catch (error) {
                // fallback method with tracked layers
                if (isArray(this.leafletLayers)) {
                    this.leafletLayers.forEach((layer) => {
                        try {
                            this.leafletMap.removeLayer(layer);
                        } catch (error) {
                            // silent error just continue with order processing if any
                        }
                    });
                }
            }
        }
    }

    @action createPlaceArrayFromPayload(payload, waypoints, isMultipleDropoffOrder = false) {
        const routePreviewArray = [];

        if (isMultipleDropoffOrder) {
            for (let i = 0; i < waypoints.length; i++) {
                if (waypoints[i].place) {
                    routePreviewArray.pushObject(waypoints[i].place);
                }
            }
        } else {
            if (payload.pickup) {
                routePreviewArray.pushObject(payload.pickup);
            }

            if (payload.dropoff) {
                routePreviewArray.pushObject(payload.dropoff);
            }
        }

        return routePreviewArray;
    }

    @action createCoordinatesFromRoutePlaceArray(array) {
        return array.filter((place) => place.get('hasValidCoordinates')).map((place) => place.get('extractedLatLng'));
    }

    @action previewDraftOrderRoute(payload, waypoints, isMultipleDropoffOrder = false) {
        const leafletMap = this.leafletMap;

        // if existing route preview on the map - remove it
        this.removeRoutingControlPreview();
        this.removeOptimizedRoute();
        this.clearLayers();

        if (!this.isRoutePreviewAnimationActive) {
            this.previewRoute(true);
        }

        this.isViewingRoutePreview = true;
        this.routePreviewArray = this.createPlaceArrayFromPayload(payload, waypoints, isMultipleDropoffOrder);

        const canPreviewRoute = this.routePreviewArray.length > 0;

        if (canPreviewRoute) {
            const routingHost = getRoutingHost(payload, waypoints);
            const router = new OSRMv1({
                serviceUrl: `${routingHost}/route/v1`,
                profile: 'driving',
            });

            // console.log('[this.routePreviewArray]', this.routePreviewArray);
            // console.log('[this.routePreviewCoordinates]', this.routePreviewCoordinates);

            this.previewRouteControl = new RoutingControl({
                waypoints: this.routePreviewCoordinates,
                alternativeClassName: 'hidden',
                addWaypoints: false,
                markerOptions: {
                    icon: L.icon({
                        iconUrl: '/assets/images/marker-icon.png',
                        iconRetinaUrl: '/assets/images/marker-icon-2x.png',
                        shadowUrl: '/assets/images/marker-shadow.png',
                        iconSize: [25, 41],
                        iconAnchor: [12, 41],
                    }),
                },
                router,
            }).addTo(leafletMap);

            this.previewRouteControl.on('routesfound', (event) => {
                const { routes } = event;
                const leafletRoute = routes.firstObject;
                this.currentLeafletRoute = event;

                this.setProperties({ leafletRoute });
            });

            if (this.routePreviewCoordinates.length === 1) {
                leafletMap.flyTo(this.routePreviewCoordinates[0], 18);
                leafletMap.once('moveend', function () {
                    leafletMap.panBy([200, 0]);
                });
            } else {
                leafletMap.flyToBounds(this.routePreviewCoordinates, {
                    paddingBottomRight: [300, 0],
                    maxZoom: this.routePreviewCoordinates.length === 2 ? 15 : 14,
                    animate: true,
                });
                leafletMap.once('moveend', function () {
                    leafletMap.panBy([150, 0]);
                });
            }
        } else {
            this.notifications.warning('No route to preview.');
        }
    }

    @action previewRoute(isViewingRoutePreview) {
        this.isViewingRoutePreview = isViewingRoutePreview;
        this.isRoutePreviewAnimationActive = isViewingRoutePreview;

        if (isViewingRoutePreview === true) {
            this.previewDraftOrderRoute(this.payload, this.waypoints, this.isMultipleDropoffOrder);
        }

        if (isViewingRoutePreview === false) {
            this.removeRoutingControlPreview();
            this.removeOptimizedRoute();
            this.removePreviewRouteLayers();
            this.clearLayers();
        }
    }

    @action async optimizeRoute() {
        this.isOptimizingRoute = true;

        const leafletMap = this.leafletMap;
        const coordinates = this.getCoordinatesFromPayload();
        const routingHost = getRoutingHost(this.payload, this.waypoints);

        const response = await this.fetch.routing(coordinates, { source: 'any', destination: 'any', annotations: true }, { host: routingHost }).catch(() => {
            this.notifications.error('Route optimization failed, check route entry and try again.');
            this.isOptimizingRoute = false;
        });

        this.isOptimizingRoute = false;

        if (response && response.code === 'Ok') {
            // remove current route display
            this.removeRoutingControlPreview();
            this.removeOptimizedRoute(leafletMap);

            let trip = response.trips.firstObject;
            let route = polyline.decode(trip.geometry);
            let sortedWaypoints = [];
            let optimizedRouteMarkers = [];

            if (response.waypoints && isArray(response.waypoints)) {
                const responseWaypoints = response.waypoints.sortBy('waypoint_index');

                this.setOptimizedRoute(route, trip, responseWaypoints);

                for (let i = 0; i < responseWaypoints.length; i++) {
                    const optimizedWaypoint = responseWaypoints.objectAt(i);
                    const optimizedWaypointLongitude = optimizedWaypoint.location.firstObject;
                    const optimizedWaypointLatitude = optimizedWaypoint.location.lastObject;
                    const waypointModel = findClosestWaypoint(optimizedWaypointLatitude, optimizedWaypointLongitude, this.waypoints);
                    // eslint-disable-next-line no-undef
                    // const optimizedWaypointMarker = new L.Marker(optimizedWaypoint.location.reverse()).addTo(leafletMap);
                    const optimizedWaypointMarker = new L.Marker(extractCoordinates(optimizedWaypoint.location)).addTo(leafletMap);

                    sortedWaypoints.pushObject(waypointModel);
                    optimizedRouteMarkers.pushObject(optimizedWaypointMarker);
                }

                this.waypoints = sortedWaypoints;
                this.optimizedRouteMarkers = optimizedRouteMarkers;
            }

            // set order as route optimized
            this.order.set('is_route_optimized', true);

            // refetch quotes
            if (this.isUsingIntegratedVendor) {
                this.getQuotes();
            }

            // eslint-disable-next-line no-undef
            let optimizedRoute = (this.optimizedRoutePolyline = new L.Polyline(route, { color: 'red' }).addTo(leafletMap));
            // leafletMap.addLayer(optimizedRoute);
            leafletMap.flyToBounds(optimizedRoute.getBounds(), {
                paddingBottomRight: [0, 600],
                animate: true,
                maxZoom: 13,
            });
        } else {
            this.notifications.error('Route optimization failed, check route entry and try again.');
            this.isOptimizingRoute = false;
        }
    }

    @action removeOptimizedRoute(_leafletMap = null) {
        this.leafletOptimizedRoute = undefined;

        const leafletMap = _leafletMap || this.leafletMap;

        if (!leafletMap) {
            return;
        }

        if (this.optimizedRoutePolyline) {
            leafletMap.removeLayer(this.optimizedRoutePolyline);
        }

        for (let i = 0; i < this.optimizedRouteMarkers.length; i++) {
            let marker = this.optimizedRouteMarkers.objectAt(i);

            leafletMap.removeLayer(marker);
        }
    }

    @action getCoordinatesFromPayload() {
        this.notifyPropertyChange('payloadCoordinates');

        return this.payloadCoordinates;
    }

    @action toggleMultiDropOrder(isMultipleDropoffOrder) {
        this.isMultipleDropoffOrder = isMultipleDropoffOrder;

        if (isMultipleDropoffOrder) {
            this.addWaypoint();
        } else {
            this.clearWaypoints();
        }
    }

    @action resetForm() {
        const order = this.store.createRecord('order', { meta: [] });
        const payload = this.store.createRecord('payload');
        const driversQuery = {};
        const meta = [];
        const entities = [];
        const waypoints = [];
        const types = [];
        const isCreatingOrder = false;
        const isMultipleDropoffOrder = false;
        const leafletRoute = undefined;
        const serviceRates = [];
        const selectedServiceRate = undefined;
        const selectedServiceQuote = undefined;
        const servicable = false;

        this.removeRoutingControlPreview();
        this.removeOptimizedRoute();
        this.setProperties({
            order,
            payload,
            driversQuery,
            meta,
            entities,
            waypoints,
            types,
            isCreatingOrder,
            isMultipleDropoffOrder,
            leafletRoute,
            serviceRates,
            selectedServiceQuote,
            selectedServiceRate,
            servicable,
        });
        this.resetInterface();
    }

    @action setConfig(type) {
        if (!type) {
            return;
        }

        set(this, 'order.type', type);
        this.meta = [];
        this.applyCustomMetaFields(type);
    }

    @action setOrderFacilitator(model) {
        this.order.set('facilitator', model);
        // this.order.set('facilitator_type', `fleet-ops:${model.facilitator_type}`);
        this.order.set('driver', null);

        this.isUsingIntegratedVendor = model.isIntegratedVendor;
        this.servicable = model.isIntegratedVendor;

        if (model.service_types?.length) {
            this.integratedVendorServiceType = model.service_types.firstObject.key;
        }

        if (model.isIntegratedVendor) {
            this.getQuotes();
        }

        if (model) {
            this.driversQuery = { facilitator: model.id };
        }
    }

    @action setOrderCustomer(model) {
        this.order.set('customer', model);
        // this.order.set('customer_type', `fleet-ops:${model.customer_type}`);
    }

    @action selectIntegratedServiceType(key) {
        this.integratedVendorServiceType = key;

        if (this.isUsingIntegratedVendor) {
            this.getQuotes();
        }
    }

    @action addCustomField() {
        let label, value;

        this.modalsManager.show('modals/meta-field-form', {
            title: 'Add custom field to order',
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            acceptButtonText: 'Done',
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            label,
            value,
            confirm: (modal) => {
                const label = modal.getOption('label');
                const value = modal.getOption('value');

                if (!label) {
                    return this.notifications.warning('Custom field must have a label');
                }

                if (!value) {
                    return this.notifications.warning('Custom field must have a value');
                }

                modal.startLoading();

                this.order.meta.pushObject({
                    key: dasherize(label),
                    label,
                    value,
                });

                modal.done();
            },
        });
    }

    @action editCustomField(index) {
        const metaField = this.order.meta.objectAt(index);
        const { label, value } = metaField;

        this.modalsManager.show('modals/meta-field-form', {
            title: 'Edit custom field',
            acceptButtonIcon: 'save',
            acceptButtonText: 'Save Changes',
            label,
            value,
            confirm: (modal) => {
                const label = modal.getOption('label');
                const value = modal.getOption('value');

                if (!label) {
                    return this.notifications.warning('Custom field must have a label');
                }

                if (!value) {
                    return this.notifications.warning('Custom field must have a value');
                }

                modal.startLoading();

                this.order.meta.replace(index, 1, [
                    {
                        key: dasherize(label),
                        label,
                        value,
                    },
                ]);

                modal.done();
            },
        });
    }

    @action editMetaData() {
        let { meta } = this.order;

        if (!isArray(meta)) {
            meta = [];
        }

        this.modalsManager.show('modals/edit-meta-form', {
            title: 'Edit Metadata',
            hideDeclineButton: true,
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            acceptButtonText: 'Done',
            meta,
            addMetaField: (meta) => {
                const label = 'New field';
                meta.pushObject({
                    key: dasherize(label),
                    label,
                    value: null,
                });
            },
            removeMetaField: (meta, index) => {
                meta.removeAt(index);
            },
            confirm: (modal) => {
                const meta = modal.getOption('meta');

                this.order.meta = meta;

                modal.done();
            },
        });
    }

    @action removeMeta(meta) {
        this.meta.removeObject(meta);
    }

    @action setPayloadPlace(prop, place) {
        this.payload[prop] = place;

        // this.previewRoute(true);
        this.previewDraftOrderRoute(this.payload, this.waypoints, this.isMultipleDropoffOrder);

        if (this.isUsingIntegratedVendor) {
            this.getQuotes();
        }
    }

    @action sortWaypoints({ sourceList, sourceIndex, targetList, targetIndex }) {
        if (sourceList === targetList && sourceIndex === targetIndex) {
            return;
        }

        const item = sourceList.objectAt(sourceIndex);

        sourceList.removeAt(sourceIndex);
        targetList.insertAt(targetIndex, item);

        if (this.isViewingRoutePreview) {
            this.previewDraftOrderRoute(this.payload, this.waypoints, this.isMultipleDropoffOrder);
        }
    }

    @action addWaypoint() {
        const waypoint = this.store.createRecord('waypoint');

        this.waypoints.pushObject(waypoint);
    }

    @action setWaypointPlace(index, place) {
        if (!this.waypoints[index]) {
            return;
        }

        this.waypoints[index].place = place;

        if (this.waypoints.length) {
            this.previewDraftOrderRoute(this.payload, this.waypoints, this.isMultipleDropoffOrder);
        }

        if (this.isUsingIntegratedVendor) {
            this.getQuotes();
        }
    }

    @action removeWaypoint(waypoint) {
        if (this.isMultipleDropoffOrder && this.waypoints.length === 1) {
            return;
        }

        this.waypoints.removeObject(waypoint);

        if (this.waypoints.length === 1) {
            this.previewRoute(false);
        } else {
            this.previewDraftOrderRoute(this.payload, this.waypoints, this.isMultipleDropoffOrder);
        }
    }

    @action clearWaypoints() {
        this.waypoints.clear();

        if (this.isViewingRoutePreview) {
            this.previewRoute(false);
        }
    }

    @action setEntityDestionation(index, { target }) {
        const { value } = target;

        this.entities[index].destination_uuid = value;
    }

    @action addEntity(importId = null) {
        const entity = this.store.createRecord('entity', {
            _import_id: importId,
        });

        this.entities.pushObject(entity);
    }

    @action removeEntity(entity) {
        if (this.entities.length === 1) {
            return;
        }

        if (!entity.get('isNew')) {
            return entity.destroyRecord();
        }

        this.entities.removeObject(entity);
    }

    @action editEntity(entity) {
        this.modalsManager.show('modals/entity-form', {
            title: 'Edit Item',
            acceptButtonText: 'Save Changes',
            entity,
            uploadNewPhoto: (file) => {
                if (entity.get('isNew')) {
                    const { queue } = file;
                    const fileUrl = URL.createObjectURL(file.blob);

                    this.modalsManager.setOption('pendingFileUpload', file);
                    entity.set('photo_url', fileUrl);
                    // entity.set('photo_upload', fileUrl);
                    queue.remove(file);
                    return;
                }

                return this.fetch.uploadFile.perform(
                    file,
                    {
                        path: `uploads/${this.currentUser.companyId}/entities/${entity.id}`,
                        subject_uuid: entity.id,
                        subject_type: `entity`,
                        type: `entity_photo`,
                    },
                    (uploadedFile) => {
                        entity.setProperties({
                            photo_uuid: uploadedFile.id,
                            photo_url: uploadedFile.url,
                            photo: uploadedFile,
                        });
                    }
                );
            },
            confirm: async (modal) => {
                modal.startLoading();

                const pendingFileUpload = modal.getOption('pendingFileUpload');

                return entity.save().then(() => {
                    if (pendingFileUpload) {
                        return modal.invoke('uploadNewPhoto', pendingFileUpload);
                    }
                });
            },
        });
    }

    @action transitionBack() {
        return this.transitionToRoute('operations.orders.index');
    }

    @action async newFacilitator() {
        const type = await this.modalsManager.userSelectOption('Select facilitator type', ['contact', 'vendor']);

        if (type === 'vendor') {
            return this.vendorsController.createVendor();
        }

        if (type === 'contact') {
            return this.contactsController.createContact();
        }
    }

    @action async newCustomer() {
        const type = await this.modalsManager.userSelectOption('Select customer type', ['contact', 'vendor']);

        if (type === 'vendor') {
            return this.vendorsController.createVendor();
        }

        if (type === 'contact') {
            return this.contactsController.createContact();
        }
    }

    @action applyCustomMetaFields(typeKey) {
        const type = this.types.find((type) => type.key === typeKey);

        if (!type || !type.meta) {
            return;
        }

        if (isArray(type.meta.fields)) {
            for (let i = 0; i < type.meta.fields.length; i++) {
                let field = type.meta.fields[i];

                this.meta.pushObject({
                    ...field,
                    value: null,
                });
            }
        }
    }
}
