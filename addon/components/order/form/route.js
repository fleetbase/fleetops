import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';
import { task } from 'ember-concurrency';
import { colorForId, routeColorForStatus, routeStyleForStatus } from '../../../utils/route-colors';
import { buildRoutePointMarkerPresentation, buildRoutePointsFromPayload, describeRoutePoint } from '../../../utils/route-visualization';

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
    @service orderCreation;
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

    get routeColor() {
        const order = this.args.resource;

        return colorForId(order?.public_id ?? order?.id ?? 'new-order');
    }

    get waypointRouteStops() {
        const waypoints = this.args.resource.payload?.waypoints ?? [];

        return waypoints.map((_waypoint, index) => {
            const routePoint = {
                role: 'waypoint',
                stopNumber: index + 1,
            };

            return {
                ...describeRoutePoint(routePoint, this.routeColor),
                badgeStyle: this.badgeStyleForWaypoint(index),
                required: this.isRequiredWaypoint(index),
            };
        });
    }

    badgeStyleForWaypoint(index) {
        const { markerColor } = describeRoutePoint({ role: 'waypoint', stopNumber: index + 1 }, this.routeColor);
        const normalizedColor = markerColor?.toLowerCase?.();
        const isYellow = normalizedColor === '#facc15' || normalizedColor === '#ca8a04';
        const textColor = isYellow ? '#111827' : '#ffffff';

        return `background-color: ${markerColor}; color: ${textColor};`;
    }

    isRequiredWaypoint(index) {
        return index < 2;
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

        this.requestServiceQuoteRefresh('route.waypoints.toggled');
    }

    @action sortWaypoints({ sourceList, sourceIndex, targetList, targetIndex }) {
        if (sourceList === targetList && sourceIndex === targetIndex) {
            return;
        }

        const item = sourceList.objectAt(sourceIndex);

        sourceList.removeAt(sourceIndex);
        targetList.insertAt(targetIndex, item);

        this.previewRoute();
        this.requestServiceQuoteRefresh('route.waypoints.reordered');
    }

    @action addWaypoint(properties = {}) {
        if (this.args.resource.customer) {
            properties.customer = this.args.resource.customer;
        }

        const waypoint = this.store.createRecord('waypoint', { ...properties, type: 'dropoff' });
        this.args.resource.payload.waypoints.pushObject(waypoint);

        this.previewRoute();
        this.requestServiceQuoteRefresh('route.waypoint.added');
    }

    @action setWaypointPlace(index, place) {
        if (!this.args.resource.payload.waypoints[index]) return;

        this.args.resource.payload.waypoints[index].place = place;
        this.args.resource.payload.waypoints[index]?.setProperties({
            street1: place.street1,
            street2: place.street2,
            city: place.city,
            province: place.province,
            postal_code: place.postal_code,
            country: place.country,
            location: place.location,
        });
        this.previewRoute();
        this.requestServiceQuoteRefresh('route.waypoint.place.changed');
    }

    @action setWaypointCustomer(waypoint, model) {
        waypoint.set('customer', model);
        waypoint.set('customer_type', `fleet-ops:${model.customer_type}`);
    }

    @action removeWaypoint(waypoint) {
        if (this.multipleWaypoints && this.args.resource.payload.waypoints.length === 1) return;
        this.args.resource.payload.waypoints.removeObject(waypoint);
        this.previewRoute();
        this.requestServiceQuoteRefresh('route.waypoint.removed');
    }

    @action clearWaypoints() {
        this.args.resource.payload.waypoints.clear();
        this.previewRoute();
        this.requestServiceQuoteRefresh('route.waypoints.cleared');
    }

    @action setPayloadPlace(prop, place) {
        this.args.resource.payload[prop] = place;
        this.previewRoute();
        this.requestServiceQuoteRefresh(`route.${prop}.changed`);
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
        this.requestServiceQuoteRefresh('route.optimized');
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
        this.requestServiceQuoteRefresh('route.changed');
    }

    requestServiceQuoteRefresh(reason) {
        this.orderCreation.requestServiceQuoteRefresh(reason, this.args.resource);
    }
}
