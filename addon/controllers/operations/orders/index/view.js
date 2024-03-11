import BaseController from '@fleetbase/fleetops-engine/controllers/base-controller';
import { inject as controller } from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action, computed } from '@ember/object';
import { later } from '@ember/runloop';
import { not, notEmpty, alias } from '@ember/object/computed';
import { task } from 'ember-concurrency-decorators';
import { OSRMv1, Control as RoutingControl } from '@fleetbase/leaflet-routing-machine';
import getRoutingHost from '@fleetbase/ember-core/utils/get-routing-host';

export default class OperationsOrdersIndexViewController extends BaseController {
    /**
     * Inject the `operations.orders.index` controller
     *
     * @var {Controller}
     */
    @controller('operations.orders.index') ordersController;

    /**
     * Inject the `management.contacts.index` controller
     *
     * @var {Controller}
     */
    @controller('management.contacts.index') contactsController;

    /**
     * Inject the `management.vendors.index` controller
     *
     * @var {Controller}
     */
    @controller('management.vendors.index') vendorsController;

    /**
     * Inject the `management.drivers.index` controller
     *
     * @var {Controller}
     */
    @controller('management.drivers.index') driversController;

    /**
     * Inject the `store` service
     *
     * @var {Service}
     */
    @service store;

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
     * Inject the `intl` service
     *
     * @var {Service}
     */
    @service intl;

    /**
     * Inject the `currentUser` service
     *
     * @var {Service}
     */
    @service currentUser;

    /**
     * Inject the `fetch` service
     *
     * @var {Service}
     */
    @service fetch;

    /**
     * Inject the `hostRouter` service
     *
     * @var {Service}
     */
    @service hostRouter;

    /**
     * Inject the `socket` service
     *
     * @var {Service}
     */
    @service socket;

    /**
     * Inject the `universe` service
     *
     * @var {Service}
     */
    @service universe;

    /**
     * Inject the `contextPanel` service
     *
     * @var {Service}
     */
    @service contextPanel;

    @tracked isLoadingAdditionalData = false;
    @tracked isWaypointsCollapsed;
    @tracked isEditingOrderNotes = false;
    @tracked leafletRoute;
    @tracked routeControl;
    @tracked commentInput = '';
    @tracked customFieldGroups = [];
    @tracked customFields = [];
    @tracked uploadQueue = [];
    acceptedFileTypes = [
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

    @alias('currentUser.latitude') userLatitude;
    @alias('currentUser.longitude') userLongitude;

    @tracked detailPanelButtons = [
        {
            type: 'default',
            text: 'Edit',
            icon: 'pencil',
            iconPrefix: 'fas',
            onClick: () => {
                const order = this.model;
                this.editOrder(order);
            },
        },
    ];

    @tracked routePanelButtons = [
        {
            type: 'default',
            text: 'Edit',
            icon: 'pencil',
            iconPrefix: 'fas',
            onClick: () => {
                const order = this.model;
                this.editOrderRoute(order);
            },
        },
    ];

    @tracked notesPanelButtons = [
        {
            type: 'default',
            text: 'Edit',
            icon: 'pencil',
            iconPrefix: 'fas',
            onClick: () => {
                this.editOrderNotes();
            },
        },
    ];

    @not('isWaypointsCollapsed') waypointsIsNotCollapsed;
    @notEmpty('model.payload.waypoints') isMultiDropOrder;
    @alias('ordersController.leafletMap') leafletMap;

    get renderableComponents() {
        const renderableComponents = this.universe.getRenderableComponentsFromRegistry('fleet-ops:template:operations:orders:view');
        return renderableComponents;
    }

    /** @var entitiesByDestination */
    @computed('model.payload.{entities.[],waypoints.[]}')
    get entitiesByDestination() {
        const groups = [];

        // create groups
        this.model.payload.waypoints.forEach((waypoint) => {
            const destinationId = waypoint.id;

            if (destinationId) {
                const entities = this.model.payload.entities.filter((entity) => entity.destination_uuid === destinationId);

                if (entities.length === 0) {
                    return;
                }

                const group = {
                    destinationId,
                    waypoint,
                    entities,
                };

                groups.pushObject(group);
            }
        });

        return groups;
    }

    @computed('model.payload.{dropoff,pickup,waypoints}') get routeWaypoints() {
        const { payload } = this.model;
        let waypoints = [];
        let coordinates = [];

        waypoints.pushObjects([payload.pickup, ...payload.waypoints.toArray(), payload.dropoff]);
        waypoints.forEach((place) => {
            if (place && place.get('longitude') && place.get('latitude')) {
                if (place.hasInvalidCoordinates) {
                    return;
                }

                coordinates.pushObject([place.get('latitude'), place.get('longitude')]);
            }
        });

        return coordinates;
    }

    @task *loadOrderRelations(order) {
        yield order.loadOrderConfig();
        yield order.loadPayload();
        yield order.loadDriver();
        yield order.loadTrackingNumber();
        yield order.loadCustomer();
        yield order.loadTrackingActivity();
        yield order.loadPurchaseRate();
        yield order.loadFiles();
        this.loadCustomFields.perform(order);
    }

    /**
     * A task method to load custom fields from the store and group them.
     * @task
     */
    @task *loadCustomFields(order) {
        const orderConfig = order.order_config;
        if (orderConfig) {
            this.customFieldGroups = yield this.store.query('category', { owner_uuid: orderConfig.id, for: 'custom_field_group' });
            this.customFields = yield this.store.query('custom-field', { subject_uuid: orderConfig.id });
            this.groupCustomFields(order);
        }
    }

    /**
     * Organizes custom fields into their respective groups.
     */
    groupCustomFields(order) {
        const customFields = Array.from(this.customFields);
        const customFieldValues = Array.from(order.custom_field_values);
        // map values to the custom fields
        const customFieldsWithValues = customFields.map((customField) => {
            customField.value = customFieldValues.find((customFieldValue) => customFieldValue.custom_field_uuid === customField.id);
            return customField;
        });
        // update custom fields with values
        this.customFields = customFieldsWithValues;
        // group custom fields
        for (let i = 0; i < this.customFieldGroups.length; i++) {
            const group = this.customFieldGroups[i];
            group.set(
                'customFields',
                customFieldsWithValues.filter((customField) => {
                    return customField.category_uuid === group.id;
                })
            );
        }
    }

    @action resetView() {
        this.removeRoutingControlPreview();
        this.resetInterface();
    }

    @action resetInterface() {
        if (this.leafletMap && this.leafletMap.liveMap) {
            this.leafletMap.liveMap.show(['drivers', 'routes']);
        }
    }

    @action removeRoutingControlPreview() {
        const { leafletMap, routeControl } = this;

        if (routeControl instanceof RoutingControl) {
            try {
                routeControl.remove();
            } catch (e) {
                // silent
            }
        }

        if (leafletMap instanceof L.Map) {
            try {
                leafletMap.removeControl(routeControl);
            } catch (e) {
                // silent
            }
        }

        this.forceRemoveRoutePreview();
    }

    @action forceRemoveRoutePreview() {
        const { leafletMap } = this;

        if (leafletMap instanceof L.Map) {
            leafletMap.eachLayer((layer) => {
                if (layer instanceof L.Polyline || layer instanceof L.Marker) {
                    layer.remove();
                }
            });
        }
    }

    @action setupInterface() {
        // always set map layout
        this.ordersController.setLayoutMode('map');

        // create initial setup function which runs 600ms after invoked
        const setup = (ms = 600) => {
            return later(
                this,
                () => {
                    if (this.leafletMap && this.leafletMap.liveMap) {
                        this.leafletMap.liveMap.hideAll();
                    }

                    // display order route on map
                    this.displayOrderRoute();
                },
                ms
            );
        };

        // create a display order route only function
        const displayOrderRoute = () => {
            return later(
                this,
                () => {
                    return this.displayOrderRoute();
                },
                300
            );
        };

        // re-display order routes when livemap has coordinates
        this.universe.on('fleet-ops.live-map.has_coordinates', this, displayOrderRoute);

        // when transitioning away kill event listener
        this.hostRouter.on('routeWillChange', () => {
            const isListening = this.universe.has('fleet-ops.live-map.has_coordinates');

            if (isListening) {
                this.universe.off('fleet-ops.live-map.has_coordinates', this, displayOrderRoute);
            }
        });

        // run setup
        setup();
    }

    getPayloadCoordinates(payload) {
        let waypoints = [];
        let coordinates = [];

        waypoints.pushObjects([payload.pickup, ...payload.waypoints.toArray(), payload.dropoff]);
        waypoints.forEach((place) => {
            if (place && place.get('longitude') && place.get('latitude')) {
                if (place.hasInvalidCoordinates) {
                    return;
                }

                coordinates.pushObject([place.get('latitude'), place.get('longitude')]);
            }
        });

        return coordinates;
    }

    getPayloadWaypointsAsArray() {
        if (this.model.payload && this.model.payload.waypoints) {
            return this.model.payload.waypoints.toArray();
        }

        return [];
    }

    @action displayOrderRoute() {
        const leafletMap = this.leafletMap;
        const payload = this.model.payload;
        const waypoints = this.getPayloadCoordinates(payload);
        const routingHost = getRoutingHost(payload, this.getPayloadWaypointsAsArray());

        if (!waypoints || waypoints.length < 2 || !leafletMap) {
            return;
        }

        // center on first coordinate
        leafletMap.stop();
        leafletMap.flyTo(waypoints.firstObject);

        const router = new OSRMv1({
            serviceUrl: `${routingHost}/route/v1`,
            profile: 'driving',
        });

        this.routeControl = new RoutingControl({
            waypoints,
            markerOptions: {
                icon: L.icon({
                    iconUrl: '/assets/images/marker-icon.png',
                    iconRetinaUrl: '/assets/images/marker-icon-2x.png',
                    shadowUrl: '/assets/images/marker-shadow.png',
                    iconSize: [25, 41],
                    iconAnchor: [12, 41],
                }),
            },
            alternativeClassName: 'hidden',
            addWaypoints: false,
            router,
        }).addTo(leafletMap);

        this.routeControl.on('routesfound', (event) => {
            const { routes } = event;

            this.leafletRoute = routes.firstObject;
        });

        later(
            this,
            () => {
                leafletMap.flyToBounds(waypoints, {
                    paddingBottomRight: [200, 0],
                    maxZoom: 14,
                    animate: true,
                });
                leafletMap.once('moveend', function () {
                    leafletMap.panBy([250, 0]);
                });
            },
            300
        );
    }

    /**
     * Edit order details.
     *
     * @param {OrderModel} order
     * @param {Object} options
     * @void
     */
    @action toggleWaypointsCollapse() {
        const _isWaypointsCollapsed = this.isWaypointsCollapsed;

        this.isWaypointsCollapsed = !_isWaypointsCollapsed;
    }

    /**
     * Edit order details.
     *
     * @param {OrderModel} order
     * @param {Object} options
     * @void
     */
    @action editOrder(order, options = {}) {
        options = options === null ? {} : options;

        this.modalsManager.show('modals/order-form', {
            title: this.intl.t('fleet-ops.operations.orders.index.view.edit-order-title'),
            acceptButtonText: 'Save Changes',
            acceptButtonIcon: 'save',
            setOrderFacilitator: (model) => {
                order.set('facilitator', model);
                order.set('facilitator_type', `fleet-ops:${model.facilitator_type}`);
                order.set('driver', null);

                if (model) {
                    this.modalsManager.setOptions('driversQuery', {
                        facilitator: model.id,
                    });
                }
            },
            setOrderCustomer: (model) => {
                order.set('customer', model);
                order.set('customer_type', `fleet-ops:${model.customer_type}`);
            },
            setDriver: (driver) => {
                order.set('driver_assigned', driver);

                if (!driver) {
                    order.set('driver_assigned_uuid', null);
                }
            },
            scheduleOrder: (dateInstance) => {
                order.scheduled_at = dateInstance.toDate();
            },
            driversQuery: {},
            order,
            confirm: (modal) => {
                modal.startLoading();
                return order
                    .save()
                    .then(() => {
                        this.notifications.success(options.successNotification || this.intl.t('fleet-ops.operations.orders.index.view.update-success', { orderId: order.public_id }));
                    })
                    .catch((error) => {
                        this.notifications.serverError(error);
                    });
            },
            decline: () => {
                order.payload.rollbackAttributes();
                this.modalsManager.done();
            },
            ...options,
        });
    }

    /**
     * View order RAW order meta.
     *
     * @param {OrderModel} order
     * @void
     */
    @action viewOrderMeta(order) {
        this.modalsManager.show('modals/order-meta', {
            title: this.intl.t('fleet-ops.operations.orders.index.view.order-metadata'),
            acceptButtonText: 'Done',
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            hideDeclineButton: true,
            order,
        });
    }

    @action editOrderNotes() {
        this.isEditingOrderNotes = true;
    }

    @task *saveOrderNotes() {
        const { notes } = this.model;
        yield this.model.persistProperty('notes', notes).then(() => {
            this.notifications.success(this.intl.t('fleet-ops.operations.orders.index.view.order-notes-updated'));
        });
        this.isEditingOrderNotes = false;
    }

    /**
     * Prompt to unassign driver from otder.
     *
     * @param {OrderModel} order
     * @param {*} [options={}]
     * @memberof OperationsOrdersIndexViewController
     */
    @action unassignDriver(order, options = {}) {
        this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.operations.orders.index.view.edit-order-title', { driverName: order.driver_assigned.name }),
            body: this.intl.t('fleet-ops.operations.orders.index.view.unassign-body'),
            order,
            confirm: (modal) => {
                modal.startLoading();

                order.setProperties({
                    driver_assigned: null,
                    driver_assigned_uuid: null,
                });

                return order
                    .save()
                    .then(() => {
                        this.notifications.success(this.intl.t('fleet-ops.operations.orders.index.view.unassign-success'));
                    })
                    .catch((error) => {
                        this.notifications.serverError(error);
                    });
            },
            ...options,
        });
    }

    /**
     * Edit order routing details.
     *
     * @param {OrderModel} order
     * @param {Object} options
     * @void
     */
    @action async editOrderRoute(order, options = {}) {
        const updateRouteDisplay = () => {
            later(
                this,
                () => {
                    this.displayOrderRoute();
                },
                100
            );
        };

        this.contextPanel.focus(order, 'editingRoute', {
            args: {
                isResizable: true,
                latitude: this.userLatitude,
                longitude: this.userLongitude,
                ...options,
            },
            onRouteChanged: () => {
                updateRouteDisplay();
            },
            onAfterSave: () => {
                this.contextPanel.clear();
                updateRouteDisplay();
            },
        });
    }

    /**
     * Cancel the currently viewing order
     *
     * @param {OrderModel} order
     * @void
     */
    @action cancelOrder(order) {
        this.ordersController.cancelOrder(order, {
            onConfirm: () => {
                order.loadTrackingActivity();
            },
        });
    }

    /**
     * Delete the currently viewing order
     *
     * @param {OrderModel} order
     * @void
     */
    @action deleteOrder(order) {
        this.ordersController.deleteOrder(order, {
            onConfirm: () => {
                return this.transitionBack();
            },
        });
    }

    /**
     * Sends the order for dispatch
     *
     * @param {OrderModel} order
     * @void
     */
    @action dispatchOrder(order) {
        this.ordersController.dispatchOrder(order, {
            onConfirm: () => {
                this.loadOrderRelations.perform(order);
            },
        });
    }

    /**
     * Sends user to this orders socket channel
     *
     * @param {OrderModel} order
     * @void
     */
    @action listenToSocket(order) {
        this.hostRouter.transitionTo('console.developers.sockets.view', `order.${order.public_id}`);
    }

    /**
     * Prompt user to update order activity
     *
     * @param {OrderModel} order
     * @void
     */
    @action async createNewActivity(order) {
        this.modalsManager.displayLoader();

        const activityOptions = await this.fetch.get(`orders/next-activity/${order.id}`);
        await this.modalsManager.done();

        this.modalsManager.show(`modals/order-new-activity`, {
            title: this.intl.t('fleet-ops.operations.orders.index.view.add-activity-title'),
            acceptButton: false,
            selected: null,
            custom: {
                status: '',
                details: '',
                code: '',
            },
            order,
            activityOptions,
            confirm: (modal) => {
                modal.startLoading();

                let { selected, custom } = modal.getOptions(['custom', 'selected']);
                let activity = selected !== 'custom' ? activityOptions[selected] : null;

                if (selected === 'custom') {
                    if (!custom.status || !custom.details || !custom.code) {
                        modal.stopLoading();

                        return this.notifications.warning(this.intl.t('fleet-ops.operations.orders.index.view.invalid-warning'));
                    }

                    activity = custom;
                }

                return this.fetch
                    .patch(`orders/update-activity/${order.id}`, {
                        activity,
                    })
                    .then(() => {
                        modal.stopLoading();
                        return later(
                            this,
                            () => {
                                return this.hostRouter.refresh();
                            },
                            100
                        );
                    })
                    .catch((error) => {
                        modal.stopLoading();
                        this.notifications.serverError(error);
                    });
            },
        });
    }

    /**
     * Prompt user to assign a driver
     *
     * @param {OrderModel} order
     * @void
     */
    @action async assignDriver(order) {
        if (order.canLoadDriver) {
            this.modalsManager.displayLoader();

            order.driver = await this.store.findRecord('driver', order.driver_uuid);
            await this.modalsManager.done();
        }

        this.modalsManager.show(`modals/order-assign-driver`, {
            title: order.driver_uuid ? this.intl.t('fleet-ops.operations.orders.index.view.change-order') : this.intl.t('fleet-ops.operations.orders.index.view.assign-order'),
            acceptButtonText: 'Save Changes',
            order,
            confirm: (modal) => {
                modal.startLoading();
                return order.save().then(() => {
                    this.notifications.success(this.intl.t('fleet-ops.operations.orders.index.view.assign-success', { orderId: order.public_id }));
                });
            },
        });
    }

    /**
     * View order label
     *
     * @param {OrderModel} order
     * @void
     */
    @action async viewOrderLabel(order) {
        // render dialog to display label within
        this.modalsManager.show(`modals/order-label`, {
            title: 'Order Label',
            modalClass: 'modal-xl',
            acceptButtonText: 'Done',
            order,
        });

        // load the pdf label from base64
        // eslint-disable-next-line no-undef
        const fileReader = new FileReader();
        const pdfStream = await this.fetch.get(`orders/label/${order.public_id}?format=base64`).then((resp) => resp.data);
        // eslint-disable-next-line no-undef
        const base64 = await fetch(`data:application/pdf;base64,${pdfStream}`);
        const blob = await base64.blob();
        // load into file reader
        fileReader.onload = (event) => {
            const data = event.target.result;
            this.modalsManager.setOption('data', data);
        };
        fileReader.readAsDataURL(blob);
    }

    /**
     * View order label
     *
     * @param {WaypointModel} waypoint
     * @void
     */
    @action async viewWaypointLabel(waypoint, dd) {
        if (dd && typeof dd.actions.close === 'function') {
            dd.actions.close();
        }

        // render dialog to display label within
        this.modalsManager.show(`modals/order-label`, {
            title: 'Waypoint Label',
            modalClass: 'modal-xl',
            acceptButtonText: 'Done',
        });

        // load the pdf label from base64
        // eslint-disable-next-line no-undef
        const fileReader = new FileReader();
        const pdfStream = await this.fetch.get(`orders/label/${waypoint.waypoint_public_id}?format=base64`).then((resp) => resp.data);
        // eslint-disable-next-line no-undef
        const base64 = await fetch(`data:application/pdf;base64,${pdfStream}`);
        const blob = await base64.blob();
        // load into file reader
        fileReader.onload = (event) => {
            const data = event.target.result;
            this.modalsManager.setOption('data', data);
        };
        fileReader.readAsDataURL(blob);
    }

    /**
     * Reloads tracking activity for this order.
     *
     * @void
     */
    @action reloadTrackingStatuses() {
        return this.model.loadTrackingActivity();
    }

    /**
     * Uses router service to transition back to `orders.index`
     *
     * @void
     */
    @action transitionBack() {
        return this.transitionToRoute('operations.orders.index');
    }

    @action async viewCustomer({ customer, customer_is_contact }) {
        if (customer_is_contact) {
            this.contactsController.viewContact(customer);
            return;
        }

        this.vendorsController.viewVendor(customer);
    }

    @action focusOrderAssignedDriver({ driver_assigned, driver_assigned_uuid, canLoadDriver }) {
        // if can load the driver then load and display via context
        if (canLoadDriver) {
            return this.store.findRecord('driver', driver_assigned_uuid).then((driver) => {
                this.contextPanel.focus(driver);
            });
        }

        // if driver already loaded use this
        if (driver_assigned) {
            this.contextPanel.focus(driver_assigned);
        }
    }

    @action async viewFacilitator({ facilitator, facilitator_is_contact }) {
        if (facilitator_is_contact) {
            this.contactsController.viewContact(facilitator);
            return;
        }

        this.vendorsController.viewVendor(facilitator);
    }

    @action addEntity(destination = null) {
        const entity = this.store.createRecord('entity', {
            payload_uuid: this.model.payload.id,
            destination_uuid: destination ? destination.id : null,
        });

        this.model.payload.entities.pushObject(entity);
    }

    @action removeEntity(entity) {
        entity.destroyRecord();
    }

    @action editEntity(entity) {
        this.modalsManager.show('modals/entity-form', {
            title: 'Edit Item',
            acceptButtonText: 'Save Changes',
            entity,
            uploadNewPhoto: (file) => {
                const fileUrl = URL.createObjectURL(file.blob);

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
                    (uploadedFile) => {
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

    @action queueFile(file) {
        // since we have dropzone and upload button within dropzone validate the file state first
        // as this method can be called twice from both functions
        if (['queued', 'failed', 'timed_out', 'aborted'].indexOf(file.state) === -1) {
            return;
        }

        // Queue and upload immediatley
        this.uploadQueue.pushObject(file);
        this.fetch.uploadFile.perform(
            file,
            {
                path: 'uploads/fleet-ops/order-files',
                subject_uuid: this.model.id,
                subject_type: 'fleet-ops:order',
                type: 'order_file',
            },
            (uploadedFile) => {
                this.model.files.pushObject(uploadedFile);
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

    @action removeFile(file) {
        return file.destroyRecord();
    }

    @action removeCustomFieldFile(customFieldValue, file) {
        customFieldValue.set('value', null);
        customFieldValue.save().then(() => {
            return file.destroyRecord();
        });
    }
}
