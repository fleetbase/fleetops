import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import contextComponentCallback from '../utils/context-component-callback';
import applyContextComponentArguments from '../utils/apply-context-component-arguments';
import getRoutingHost from '@fleetbase/ember-core/utils/get-routing-host';
import Point from '@fleetbase/fleetops-data/utils/geojson/point';
import findClosestWaypoint from '@fleetbase/ember-core/utils/find-closest-waypoint';

export default class EditOrderRoutePanelComponent extends Component {
    /**
     * Fetch service.
     *
     * @type {Service}
     */
    @service fetch;

    /**
     * Ember data store service.
     *
     * @type {Service}
     */
    @service store;

    /**
     * Service for managing routing within the host app.
     *
     * @type {Service}
     */
    @service hostRouter;

    /**
     * Service for managing the modals.
     *
     * @type {Service}
     */
    @service modalsManager;

    /**
     * Service for managing the context panel.
     *
     * @type {Service}
     */
    @service contextPanel;

    /**
     * Service for internationalization.
     *
     * @type {Service}
     */
    @service intl;

    /**
     * Service for notifications
     *
     * @type {Service}
     */
    @service notifications;

    /**
     * The orderwhich route is being edited.
     *
     * @type {OrderModel}
     * @tracked
     */
    @tracked order;

    /**
     * Initializes the vehicle panel component.
     */
    constructor() {
        super(...arguments);
        this.order = this.args.order;
        applyContextComponentArguments(this);
    }

    /**
     * Sets the overlay context.
     *
     * @action
     * @param {OverlayContextObject} overlayContext
     */
    @action setOverlayContext(overlayContext) {
        this.context = overlayContext;
        contextComponentCallback(this, 'onLoad', ...arguments);
    }

    /**
     * Handles save action for the order route.
     *
     * @method
     * @action
     */
    @action onSave() {
        const isActionOverrided = contextComponentCallback(this, 'onSave', this.order);

        if (!isActionOverrided) {
            return this.order
                .save()
                .then((order) => {
                    this.notifications.success(this.intl.t('fleet-ops.operations.orders.index.view.update-success', { orderId: order.public_id }));
                    contextComponentCallback(this, 'onAfterSave', order);
                })
                .catch((error) => {
                    this.notifications.serverError(error);
                });
        }
    }

    /**
     * Handles the cancel action.
     *
     * @method
     * @action
     * @returns {Boolean} Indicates whether the cancel action was overridden.
     */
    @action onPressCancel() {
        return contextComponentCallback(this, 'onPressCancel', this.order);
    }

    @action async editPlace(place) {
        await this.modalsManager.done();

        this.contextPanel.focus(place, 'editing', {
            args: {
                onClose: () => {
                    this.editOrderRoute(this.order);
                },
            },
        });
    }

    @action toggleMultiDropOrder(isMultiDrop) {
        const { pickup, dropoff } = this.order.payload;

        if (isMultiDrop) {
            // if pickup move it to multipdrop
            if (pickup) {
                this.addWaypointFromExistingPlace(pickup);
            }

            // if pickup move it to multipdrop
            if (dropoff) {
                this.addWaypointFromExistingPlace(dropoff);
            }

            this.order.payload.setProperties({
                pickup_uuid: null,
                dropoff_uuid: null,
            });
        } else {
            const waypoint = this.store.createRecord('waypoint');
            this.order.payload.waypoints.pushObject(waypoint);

            this.order.payload.setProperties({
                pickup_uuid: null,
                dropoff_uuid: null,
                pickup: null,
                dropoff: null,
            });
        }

        contextComponentCallback(this, 'onRouteChanged');
    }

    addWaypointFromExistingPlace(place) {
        const waypoint = this.store.createRecord('waypoint', { place, location: place.location });
        this.order.payload.waypoints.pushObject(waypoint);

        // fire callback
        contextComponentCallback(this, 'onWaypointAdded', waypoint);
    }

    @action removeWaypoint(waypoint) {
        this.order.payload.waypoints.removeObject(waypoint);

        // fire callback
        contextComponentCallback(this, 'onWaypointRemoved', waypoint);
        contextComponentCallback(this, 'onRouteChanged');
    }

    @action addWaypoint() {
        const location = new Point(0, 0);
        const place = this.store.createRecord('place', { location });
        const waypoint = this.store.createRecord('waypoint', { place, location });

        this.order.payload.waypoints.pushObject(waypoint);
        // fire callback
        contextComponentCallback(this, 'onWaypointAdded', waypoint);
    }

    @action setWaypointPlace(index, place) {
        if (isArray(this.order.payload.waypoints) && !this.order.payload.waypoints.objectAt(index)) {
            return;
        }

        this.order.payload.waypoints.objectAt(index).setProperties({
            name: place.name,
            place_uuid: place.id,
            location: place.location,
            place,
        });

        // fire callback waypoint place selected
        contextComponentCallback(this, 'onWaypointPlaceSelected', place);
        contextComponentCallback(this, 'onRouteChanged');
    }

    @action setPayloadPlace(prop, place) {
        this.order.payload.set(prop, place);

        // fire callback
        contextComponentCallback(this, 'onPlaceSelected', place);
        contextComponentCallback(this, 'onRouteChanged');
    }

    @action async optimizeRoute() {
        this.isOptimizingRoute = true;

        const coordinates = this.order.payload.payloadCoordinates;
        const routingHost = getRoutingHost(this.order.payload, this.order.payload.waypoints);
        let sortedWaypoints = [];

        const response = await this.fetch.routing(coordinates, { source: 'any', destination: 'any', annotations: true }, { host: routingHost }).catch(() => {
            this.notifications.error(this.intl.t('fleet-ops.operations.orders.index.view.route-error'));
            this.isOptimizingRoute = false;
        });

        if (response && response.code === 'Ok') {
            if (response.waypoints && isArray(response.waypoints)) {
                const responseWaypoints = response.waypoints.sortBy('waypoint_index');

                for (let i = 0; i < responseWaypoints.length; i++) {
                    const optimizedWaypoint = responseWaypoints.objectAt(i);
                    const optimizedWaypointLongitude = optimizedWaypoint.location.firstObject;
                    const optimizedWaypointLatitude = optimizedWaypoint.location.lastObject;
                    const waypointModel = findClosestWaypoint(optimizedWaypointLatitude, optimizedWaypointLongitude, this.order.payload.waypoints);

                    sortedWaypoints.pushObject(waypointModel);
                }

                this.order.payload.waypoints = sortedWaypoints;
            }
        } else {
            this.notifications.error(this.intl.t('fleet-ops.operations.orders.index.view.route-error'));
        }

        this.isOptimizingRoute = false;
    }

    @action sortWaypoints({ sourceList, sourceIndex, targetList, targetIndex }) {
        if (sourceList === targetList && sourceIndex === targetIndex) {
            return;
        }

        const item = sourceList.objectAt(sourceIndex);

        sourceList.removeAt(sourceIndex);
        targetList.insertAt(targetIndex, item);

        contextComponentCallback(this, 'onRouteChanged');
    }
}
