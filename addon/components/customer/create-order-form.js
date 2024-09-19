import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action, computed, get, setProperties } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency';
import { OSRMv1, Control as RoutingControl } from '@fleetbase/leaflet-routing-machine';
import getRoutingHost from '@fleetbase/ember-core/utils/get-routing-host';
import polyline from '@fleetbase/ember-core/utils/polyline';
import findClosestWaypoint from '@fleetbase/ember-core/utils/find-closest-waypoint';
import engineService from '@fleetbase/ember-core/decorators/engine-service';
import registerComponent from '../../utils/register-component';
import registerHelper from '../../utils/register-helper';
import WaypointLabelHelper from '../../helpers/waypoint-label';
import isModel from '@fleetbase/ember-core/utils/is-model';

const MAP_TARGET_FOCUS_PADDING_BOTTOM_RIGHT = [200, 0];
const MAP_TARGET_FOCUS_REFOCUS_PANBY = [150, 0];
export default class CustomerCreateOrderFormComponent extends Component {
    @engineService('@fleetbase/fleetops-engine') contextPanel;
    @service store;
    @service currentUser;
    @service notifications;
    @service modalsManager;
    @service customerSession;
    @service fetch;
    @service intl;
    @tracked order;
    @tracked customer;
    @tracked payload = this.store.createRecord('payload');
    @tracked payloadCoordinates = [];
    @tracked entities = [];
    @tracked waypoints = [];
    @tracked podOptions = ['scan', 'signature', 'photo'];
    @tracked serviceRates = [];
    @tracked serviceQuotes = [];
    @tracked selectedServiceRate;
    @tracked selectedServiceQuote;
    @tracked scheduledDate;
    @tracked scheduledTime;
    @tracked isMultipleDropoffOrder = false;
    @tracked isViewingRoutePreview = false;
    @tracked routePreviewArray = [];
    @tracked map;
    @tracked latitude;
    @tracked longitude;
    @tracked leafletRoute;
    @tracked uploadQueue = [];
    @tracked acceptedFileTypes = [
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/msword',
        'application/pdf',
        'application/x-pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'video/mp4',
        'video/quicktime',
        'video/x-msvideo',
        'video/x-flv',
        'video/x-ms-wmv',
        'audio/mpeg',
        'video/x-msvideo',
        'application/zip',
        'application/x-tar',
    ];

    @computed('payloadCoordinates.length', 'waypoints.[]') get isServicable () {
        return this.payloadCoordinates.length >= 2;
    }

    @computed('routePreviewArray.[]') get routePreviewCoordinates () {
        return this.routePreviewArray.map(place => place.get('latlng'));
    }

    constructor (owner, { order, latitude, longitude, map }) {
        super(...arguments);
        this.order = order;
        this.customer = this.order.customer || this.customerSession.getCustomer();
        this.map = map;
        this.latitude = latitude;
        this.longitude = longitude;
        registerHelper(owner, WaypointLabelHelper, 'waypoint-label');
    }

    @task *createOrder () {
        // do
    }

    @task *getQuotes () {
        let payload = this.payload.serialize();
        let route = this.getRoute();
        let distance = get(route, 'details.summary.totalDistance');
        let time = get(route, 'details.summary.totalTime');
        let service_type = this.order.type;
        let scheduled_at = this.order.scheduled_at;
        let facilitator = this.order.facilitator?.get('public_id');
        let is_route_optimized = this.order.get('is_route_optimized');
        let { waypoints, entities } = this;
        let places = [];

        if (this.payloadCoordinates.length < 2) {
            return;
        }

        // get place instances from WaypointModel
        for (let i = 0; i < waypoints.length; i++) {
            let place = yield waypoints[i].place;

            places.pushObject(place);
        }

        setProperties(payload, { type: this.order.type, waypoints: places, entities });

        try {
            const serviceQuotes = yield this.fetch.post('service-quotes/preliminary', {
                payload: this._getSerializedPayload(payload),
                distance,
                time,
                service,
                service_type,
                facilitator,
                scheduled_at,
                is_route_optimized,
            });

            this.serviceQuotes = isArray(serviceQuotes) ? serviceQuotes : [];
            if (this.serviceQuotes.length) {
                this.selectedServiceQuote = this.serviceQuotes.firstObject.uuid;
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action cancelOrderCreation () {
        if (typeof this.args.onCancel === 'function') {
            this.args.onCancel();
        }
    }

    @action async toggleProofOfDelivery (on) {
        this.order.pod_required = on;

        if (on) {
            this.order.pod_method = 'scan';
        } else {
            this.order.pod_method = null;
        }
    }

    @action toggleMultiDropOrder (isMultipleDropoffOrder) {
        this.isMultipleDropoffOrder = isMultipleDropoffOrder;

        const { pickup, dropoff } = this.payload;

        if (isMultipleDropoffOrder) {
            if (pickup) {
                this.addWaypoint({ place: pickup, customer: this.order.customer });

                if (dropoff) {
                    this.addWaypoint({ place: dropoff, customer: this.order.customer });
                }

                // clear pickup and dropoff
                this.payload.setProperties({ pickup: null, dropoff: null });
            } else {
                this.addWaypoint({ customer: this.order.customer });
            }
        } else {
            const pickup = get(this.waypoints, '0.place');
            const dropoff = get(this.waypoints, '1.place');

            if (pickup) {
                this.setPayloadPlace('pickup', pickup);
            }

            if (dropoff) {
                this.setPayloadPlace('dropoff', dropoff);
            }

            this.clearWaypoints();
        }
    }

    @action previewDraftOrderRoute (payload, waypoints = [], isMultipleDropoffOrder = false) {
        this.removeRoutingControlPreview();
        this.isViewingRoutePreview = true;
        this.routePreviewArray = this.createPlaceArrayFromPayload(payload, waypoints, isMultipleDropoffOrder);

        const canPreviewRoute = this.routePreviewArray.length > 0;
        if (canPreviewRoute) {
            const routingHost = getRoutingHost(payload, waypoints);
            const router = new OSRMv1({
                serviceUrl: `${routingHost}/route/v1`,
                profile: 'driving',
            });

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
                    draggable: false,
                },
                router,
            }).addTo(this.map);

            this.previewRouteControl.on('routesfound', event => {
                const { routes } = event;
                const leafletRoute = routes.firstObject;
                this.currentLeafletRoute = event;
                this.leafletRoute = leafletRoute;
            });

            if (this.routePreviewCoordinates.length === 1) {
                this.map.flyTo(this.routePreviewCoordinates[0], 18);
                this.map.once('moveend', () => {
                    this.map.panBy(MAP_TARGET_FOCUS_REFOCUS_PANBY);
                });
            } else {
                this.map.flyToBounds(this.routePreviewCoordinates, {
                    paddingBottomRight: MAP_TARGET_FOCUS_PADDING_BOTTOM_RIGHT,
                    maxZoom: this.routePreviewCoordinates.length === 2 ? 16 : 15,
                    animate: true,
                });
                this.map.once('moveend', () => {
                    this.map.panBy(MAP_TARGET_FOCUS_REFOCUS_PANBY);
                });
            }
        } else {
            this.notifications.warning(this.intl.t('fleet-ops.operations.orders.index.new.no-route-warning'));
        }
    }

    @action removeRoutingControlPreview () {
        if (this.map && this.previewRouteControl instanceof RoutingControl) {
            try {
                this.previewRouteControl.remove();
            } catch (e) {
                try {
                    this.map.removeControl(this.previewRouteControl);
                } catch (e) {
                    // silent
                }
            }
        }
    }

    @action createPlaceArrayFromPayload (payload, waypoints, isMultipleDropoffOrder = false) {
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

    @action createPlace () {
        const place = this.store.createRecord('place', {
            owner_uuid: this.customer.id,
            owner_type: 'fleet-ops:contact',
        });
        this.modalsManager.show('modals/place-form', {
            title: 'New Place',
            place,
        });
    }

    @action editPlace (place) {
        this.modalsManager.show('modals/place-form', {
            title: 'Edit Place',
            place,
        });
    }

    @action setPayloadPlace (prop, place) {
        this.payload[prop] = place;

        this.updatePayloadCoordinates();
        this.previewDraftOrderRoute(this.payload, this.waypoints, this.isMultipleDropoffOrder);
        this.getQuotes.perform();
    }

    @action sortWaypoints ({ sourceList, sourceIndex, targetList, targetIndex }) {
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

    @action addWaypoint (properties = {}) {
        if (this.order.customer) {
            properties.customer = this.order.customer;
        }

        const waypoint = this.store.createRecord('waypoint', properties);
        this.waypoints.pushObject(waypoint);
        this.updatePayloadCoordinates();
        this.getQuotes.perform();
    }

    @action setWaypointPlace (index, place) {
        if (!this.waypoints[index]) {
            return;
        }

        // Set owner of place to current customer
        if (this.customer) {
            place.setProperties({
                owner_uuid: this.customer.id,
                owner_type: 'fleet-ops:contact',
            });
        }

        this.waypoints[index].place = place;

        if (this.waypoints.length) {
            this.previewDraftOrderRoute(this.payload, this.waypoints, this.isMultipleDropoffOrder);
        }

        this.getQuotes();
        this.updatePayloadCoordinates();
    }

    @action removeWaypoint (waypoint) {
        if (this.isMultipleDropoffOrder && this.waypoints.length === 1) {
            return;
        }

        this.waypoints.removeObject(waypoint);

        this.previewDraftOrderRoute(this.payload, this.waypoints, this.isMultipleDropoffOrder);
        this.updatePayloadCoordinates();
    }

    @action clearWaypoints () {
        this.waypoints.clear();

        if (this.isViewingRoutePreview) {
            this.previewRoute(false);
        }
    }

    @action addEntity (importId = null) {
        const entity = this.store.createRecord('entity', {
            _import_id: importId,
        });

        this.entities.pushObject(entity);
    }

    @action removeEntity (entity) {
        if (this.entities.length === 1) {
            return;
        }

        if (!entity.get('isNew')) {
            return entity.destroyRecord();
        }

        this.entities.removeObject(entity);
    }

    @action editEntity (entity) {
        this.modalsManager.show('modals/entity-form', {
            title: this.intl.t('fleet-ops.operations.orders.index.new.edit-item'),
            acceptButtonText: 'Save Changes',
            entity,
            uploadNewPhoto: file => {
                const fileUrl = URL.createObjectURL(file.file);

                if (entity.get('isNew')) {
                    const { queue } = file;

                    this.modalsManager.setOption('pendingFileUpload', file);
                    entity.set('photo_url', fileUrl);
                    queue.remove(file);
                    return;
                } else {
                    entity.set('photo_url', fileUrl);
                }

                // Indicate loading
                this.modalsManager.startLoading();

                // Perform upload
                return this.fetch.uploadFile.perform(
                    file,
                    {
                        path: `uploads/${this.currentUser.companyId}/entities/${entity.id}`,
                        subject_uuid: entity.id,
                        subject_type: 'fleet-ops:entity',
                        type: 'entity_photo',
                    },
                    uploadedFile => {
                        entity.setProperties({
                            photo_uuid: uploadedFile.id,
                            photo_url: uploadedFile.url,
                            photo: uploadedFile,
                        });

                        // Stop loading
                        this.modalsManager.stopLoading();
                    },
                    () => {
                        // Stop loading
                        this.modalsManager.stopLoading();
                    }
                );
            },
            confirm: async modal => {
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

    @action getRoute () {
        const details = this.leafletRoute;
        const route = this.store.createRecord('route', { details });

        return route;
    }

    @task *queueFile (file) {
        // since we have dropzone and upload button within dropzone validate the file state first
        // as this method can be called twice from both functions
        if (['queued', 'failed', 'timed_out', 'aborted'].indexOf(file.state) === -1) {
            return;
        }

        // Queue and upload immediatley
        this.uploadQueue.pushObject(file);
        yield this.fetch.uploadFile.perform(
            file,
            {
                path: 'uploads/fleet-ops/order-files',
                type: 'order_file',
            },
            uploadedFile => {
                this.order.files.pushObject(uploadedFile);
                this.uploadQueue.removeObject(file);
            },
            () => {
                this.uploadQueue.removeObject(file);
                // remove file from queue
                if (file.queue && typeof file.queue.remove === 'function') {
                    file.queue.remove(file);
                }
            }
        );
    }

    @action removeFile (file) {
        return file.destroyRecord();
    }

    updatePayloadCoordinates () {
        let waypoints = [];
        let coordinates = [];

        waypoints.pushObjects([this.payload.pickup, ...this.waypoints.map(waypoint => waypoint.place), this.payload.dropoff]);
        waypoints.forEach(place => {
            if (place && place.get('longitude') && place.get('latitude')) {
                if (place.hasInvalidCoordinates) {
                    return;
                }

                coordinates.pushObject([place.get('longitude'), place.get('latitude')]);
            }
        });

        this.payloadCoordinates = coordinates;
    }

    _getSerializedPayload (payload) {
        const serialized = {
            pickup: this._seriailizeModel(payload.pickup),
            dropoff: this._seriailizeModel(payload.dropoff),
            entitities: this._serializeArray(payload.entities),
            waypoints: this._serializeArray(payload.waypoint),
        };

        return serialized;
    }

    _seriailizeModel (model) {
        if (isModel(model)) {
            if (typeof model.toJSON === 'function') {
                return model.toJSON();
            }

            if (typeof model.serialize === 'function') {
                return model.serialize();
            }
        }

        return model;
    }

    _serializeArray (array) {
        return isArray(array) ? array.map(item => this._seriailizeModel(item)) : array;
    }
}
