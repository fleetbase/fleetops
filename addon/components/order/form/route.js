import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';
import { task } from 'ember-concurrency';
import { colorForId, routeColorForStatus, routeStyleForStatus } from '../../../utils/route-colors';
import { buildRoutePointMarkerPresentation, buildRoutePointsFromPayload } from '../../../utils/route-visualization';

const ORDER_ROUTE_PREVIEW_PADDING_BOTTOM_RIGHT = [420, 0];
const ORDER_ROUTE_PREVIEW_MAX_ZOOM_TWO_POINTS = 13;
const ORDER_ROUTE_PREVIEW_MAX_ZOOM_MULTI_POINTS = 12;
const ORDER_ROUTE_PREVIEW_SINGLE_POINT_PANBY = [10, 0];

export default class OrderFormRouteComponent extends Component {
    @service mapManager;
    @service routeEngine;
    @service routeOptimization;
    @service location;
    @service store;
    @service currentUser;
    @service notifications;
    @service placeActions;
    @tracked multipleWaypoints = false;
    @tracked routingControl;
    @tracked route;

    focusPlace(place, zoom = 18) {
        if (place?.hasValidCoordinates) {
            this.mapManager.positionWaypoints([[place.latitude, place.longitude]], {
                singlePointZoom: zoom,
                panBy: ORDER_ROUTE_PREVIEW_SINGLE_POINT_PANBY,
            });
        }
    }

    get coordinates() {
        return this.routePoints.map(({ place }) => [place.latitude, place.longitude]);
    }

    get places() {
        return this.routePoints.map(({ place }) => place);
    }

    get routePoints() {
        return buildRoutePointsFromPayload(this.args.resource.payload);
    }

    willDestroy() {
        super.willDestroy(...arguments);
        if (this.routingControl) {
            this.mapManager.removeRoutingControl(this.routingControl);
        }
    }

    @action toggleWaypoints(multipleWaypoints) {
        this.multipleWaypoints = multipleWaypoints;

        const { pickup, dropoff } = this.args.resource.payload;

        if (multipleWaypoints) {
            if (pickup) {
                this.addWaypoint({ place: pickup, customer: this.args.resource.customer });
                if (dropoff) {
                    this.addWaypoint({ place: dropoff, customer: this.args.resource.customer });
                }

                // clear pickup and dropoff
                this.args.resource.payload.setProperties({ pickup: null, dropoff: null });
            } else {
                this.addWaypoint({ customer: this.args.resource.customer });
            }
        } else {
            const pickup = get(this.args.resource.payload.waypoints, '0.place');
            const dropoff = get(this.args.resource.payload.waypoints, '1.place');

            if (pickup) {
                this.setPayloadPlace('pickup', pickup);
            }

            if (dropoff) {
                this.setPayloadPlace('dropoff', dropoff);
            }

            this.clearWaypoints();
        }
    }

    @action sortWaypoints({ sourceList, sourceIndex, targetList, targetIndex }) {
        if (sourceList === targetList && sourceIndex === targetIndex) {
            return;
        }

        const item = sourceList.objectAt(sourceIndex);

        sourceList.removeAt(sourceIndex);
        targetList.insertAt(targetIndex, item);

        this.previewRoute();
    }

    @action addWaypoint(properties = {}) {
        if (this.args.resource.customer) {
            properties.customer = this.args.resource.customer;
        }

        const waypoint = this.store.createRecord('waypoint', { ...properties, type: 'dropoff' });
        this.args.resource.payload.waypoints.pushObject(waypoint);

        this.previewRoute();
    }

    @action setWaypointPlace(index, place) {
        if (!this.args.resource.payload.waypoints[index]) return;

        this.args.resource.payload.waypoints[index].place = place;
        this.args.resource.payload.waypoints[index]?.setProperties(place.serialize());
        this.previewRoute();
    }

    @action setWaypointCustomer(waypoint, model) {
        waypoint.set('customer', model);
        waypoint.set('customer_type', `fleet-ops:${model.customer_type}`);
    }

    @action removeWaypoint(waypoint) {
        if (this.multipleWaypoints && this.args.resource.payload.waypoints.length === 1) return;
        this.args.resource.payload.waypoints.removeObject(waypoint);
        this.previewRoute();
    }

    @action clearWaypoints() {
        this.args.resource.payload.waypoints.clear();
        this.previewRoute();
    }

    @action setPayloadPlace(prop, place) {
        this.args.resource.payload[prop] = place;
        this.previewRoute();
    }

    @action editPlace(place) {
        this.placeActions.modal.edit(place);
    }

    @action async previewRoute() {
        if (!this.coordinates.length) {
            if (this.routingControl) {
                this.mapManager.removeRoutingControl(this.routingControl);
                this.routingControl = null;
            }

            return;
        }

        const order = this.args.resource;
        const orderId = order.public_id ?? order.id ?? 'new-order';
        const routeColor = colorForId(orderId);
        const routeStatus = order.status ?? 'created';
        const statusColor = routeColorForStatus(routeStatus);
        const routeStyles = routeStyleForStatus(routeStatus, statusColor);
        const isSinglePointPreview = this.coordinates.length === 1;
        const fitOptions = isSinglePointPreview
            ? {
                  paddingBottomRight: [0, 0],
                  panBy: ORDER_ROUTE_PREVIEW_SINGLE_POINT_PANBY,
              }
            : {
                  paddingBottomRight: ORDER_ROUTE_PREVIEW_PADDING_BOTTOM_RIGHT,
                  panBy: [0, 0],
                  maxZoom: this.coordinates.length === 2 ? ORDER_ROUTE_PREVIEW_MAX_ZOOM_TWO_POINTS : ORDER_ROUTE_PREVIEW_MAX_ZOOM_MULTI_POINTS,
              };
        const routingControl = await this.mapManager.replaceRoutingControl(this.coordinates, this.routingControl, {
            engine: this.routeEngine.getDisplayEngine('osrm'),
            color: statusColor,
            orderId,
            status: routeStatus,
            fitOptions,
            places: this.places,
            markerWaypoints: this.coordinates,
            polylineOptions: {
                color: statusColor,
                weight: routeStyles.at(-1)?.weight ?? 4,
                opacity: routeStyles.at(-1)?.opacity ?? 0.85,
                styles: routeStyles,
            },
            createMarker: (_waypoint, index) => {
                const routePoint = this.routePoints[index];
                return buildRoutePointMarkerPresentation(routePoint, routeColor);
            },
            onRouteFound: (route) => this.setRoute(route),
            removeOptions: {
                filter: (handle) => handle?.tag === this.args.resource.driver_assigned?.id,
            },
            tag: this.args.resource.driver_assigned?.id,
        });

        this.routingControl = routingControl;
    }

    @task *optimizeRouteWithService(service) {
        const order = this.args.resource;
        const payload = order.payload;
        const waypoints = this.args.resource.payload.waypoints;
        const coordinates = this.coordinates.map((coord) => coord.reverse());

        try {
            const result = yield this.routeOptimization.optimize(service, {
                context: 'create_order',
                order,
                payload,
                waypoints,
                coordinates,
            });
            this.handleRouteOptimization(result);
        } catch (err) {
            this.notifications.error(err.message ?? this.intl.t('fleet-ops.operations.orders.index.new.route-error'));
        }
    }

    @task *optimizeRoute() {
        const order = this.args.resource;
        const payload = order.payload;
        const waypoints = this.args.resource.payload.waypoints;
        const coordinates = this.coordinates.map((coord) => coord.reverse());
        const service = this.routeEngine.getOptimizationEngine('osrm');

        try {
            const result = yield this.routeOptimization.optimize(service, {
                context: 'create_order',
                order,
                payload,
                waypoints,
                coordinates,
            });

            this.handleRouteOptimization(result);
        } catch (err) {
            this.notifications.error(this.intl.t('fleet-ops.operations.orders.index.new.route-error'));
        }
    }

    @action handleRouteOptimization({ sortedWaypoints, route, trip, result, engine = 'osrm' }) {
        // Update controller state
        this.args.resource.payload.waypoints = sortedWaypoints;
        if (route) {
            this.setOptimizedRoute(route, trip, result.waypoints, engine);
        }
        this.previewRoute();
        this.args.resource.set('optimized', true);
    }

    @action setOptimizedRoute(route, trip, waypoints, engine = 'osrm') {
        let summary = {
            totalDistance: trip?.distance ?? trip?.totalDistance ?? 0,
            totalTime: trip?.duration ?? trip?.totalTime ?? 0,
        };
        let payload = {
            optimized: true,
            coordinates: route,
            waypoints,
            trip,
            summary,
            engine,
        };

        this.setRoute(payload);
    }

    setRoute(payload) {
        const routeModel = this.store.createRecord('route', payload);
        this.args.resource.route = routeModel;
        this.route = payload;
    }
}
