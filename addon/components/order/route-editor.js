import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import { buildRoutePointsFromPayload, describeRoutePoint } from '../../utils/route-visualization';
import {
    applyOptimizedIntermediateWaypoints,
    buildRouteOptimizationInput,
    createWaypointRecord,
    getPayloadIntermediateWaypoints,
    getPayloadRouteCoordinates,
} from '../../utils/order-route-editing';

export default class OrderRouteEditorComponent extends Component {
    @service store;
    @service intl;
    @service notifications;
    @service placeActions;
    @service routeEngine;
    @service routeOptimization;
    @tracked route;

    get payload() {
        return this.args.resource.payload;
    }

    get routePoints() {
        return buildRoutePointsFromPayload(this.payload);
    }

    get routeStops() {
        return this.routePoints.map((routePoint) => ({
            routePoint,
            place: routePoint.place,
            badgeStyle: this.badgeStyleForRoutePoint(routePoint),
            ...describeRoutePoint(routePoint, '#8B5CF6'),
        }));
    }

    get routeSummary() {
        const segments = [];

        if (this.payload?.pickup) {
            segments.push('Pickup');
        }

        if (this.intermediateWaypoints.length) {
            segments.push(`${this.intermediateWaypoints.length} ${this.intermediateWaypoints.length === 1 ? 'Stop' : 'Stops'}`);
        }

        if (this.payload?.dropoff) {
            segments.push('Dropoff');
        }

        if (this.payload?.return) {
            segments.push('Return');
        }

        return segments.join('  •  ');
    }

    get pickupStop() {
        return this.routeStops.find((stop) => stop.routePoint?.role === 'pickup') ?? this.fallbackStop('pickup');
    }

    get dropoffStop() {
        return this.routeStops.find((stop) => stop.routePoint?.role === 'dropoff') ?? this.fallbackStop('dropoff');
    }

    get returnStop() {
        return {
            label: 'R',
            title: 'Return',
            badgeStyle: this.badgeStyleForColor('#6B7280'),
        };
    }

    get stopsSectionBadgeStyle() {
        return this.badgeStyleForColor('#8B5CF6');
    }

    get intermediateRouteStops() {
        return this.routeStops.filter((stop) => stop.routePoint?.role === 'waypoint');
    }

    get coordinates() {
        return getPayloadRouteCoordinates(this.payload);
    }

    get intermediateWaypoints() {
        return getPayloadIntermediateWaypoints(this.payload);
    }

    get canOptimizeRoute() {
        return this.intermediateWaypoints.length >= 2;
    }

    badgeStyleForRoutePoint(routePoint) {
        const { markerColor } = describeRoutePoint(routePoint, '#8B5CF6');
        return this.badgeStyleForColor(markerColor);
    }

    badgeStyleForColor(color) {
        const normalizedColor = color?.toLowerCase?.();
        const isYellow = normalizedColor === '#facc15' || normalizedColor === '#ca8a04';
        const textColor = isYellow ? '#111827' : '#ffffff';

        return `background-color: ${color}; color: ${textColor};`;
    }

    fallbackStop(role) {
        const routePoint = {
            role,
            stopNumber: role === 'pickup' ? null : role === 'dropoff' ? null : 1,
        };

        return {
            badgeStyle: this.badgeStyleForRoutePoint(routePoint),
            ...describeRoutePoint(routePoint, '#8B5CF6'),
        };
    }

    @action removeWaypoint(waypoint) {
        this.payload.waypoints.removeObject(waypoint);

        if (typeof this.args.onWaypointRemoved === 'function') {
            this.args.onWaypointRemoved(waypoint);
        }

        if (typeof this.args.onRouteChanged === 'function') {
            this.args.onRouteChanged();
        }
    }

    @action addWaypoint() {
        const waypoint = createWaypointRecord(this.store);
        this.payload.waypoints.pushObject(waypoint);

        if (typeof this.args.onWaypointAdded === 'function') {
            this.args.onWaypointAdded(waypoint);
        }

        if (typeof this.args.onRouteChanged === 'function') {
            this.args.onRouteChanged();
        }
    }

    @action setWaypointPlace(index, place) {
        const waypoints = this.intermediateWaypoints;
        const waypoint = waypoints[index];

        if (!waypoint) {
            return;
        }

        const json = typeof place?.serialize === 'function' ? place.serialize() : {};
        waypoint.setProperties({
            uuid: waypoint.uuid ?? place.id,
            place_uuid: place.id,
            location: place.location,
            place,
            ...json,
        });

        if (typeof this.args.onWaypointPlaceSelected === 'function') {
            this.args.onWaypointPlaceSelected(place);
        }

        if (typeof this.args.onRouteChanged === 'function') {
            this.args.onRouteChanged();
        }
    }

    @action setPayloadPlace(prop, place) {
        this.payload.set(prop, place);

        if (typeof this.args.onPlaceSelected === 'function') {
            this.args.onPlaceSelected(place);
        }

        if (typeof this.args.onRouteChanged === 'function') {
            this.args.onRouteChanged();
        }
    }

    @action clearPayloadPlace(prop) {
        this.payload.set(prop, null);

        if (typeof this.args.onRouteChanged === 'function') {
            this.args.onRouteChanged();
        }
    }

    @action sortWaypoints({ sourceList, sourceIndex, targetList, targetIndex }) {
        if (sourceList === targetList && sourceIndex === targetIndex) return;

        const item = sourceList.objectAt(sourceIndex);

        sourceList.removeAt(sourceIndex);
        targetList.insertAt(targetIndex, item);

        if (typeof this.args.onRouteChanged === 'function') {
            this.args.onRouteChanged();
        }
    }

    @task *optimizeRouteWithService(service) {
        const optimizationInput = buildRouteOptimizationInput(this.args.resource);

        try {
            const result = yield this.routeOptimization.optimize(service, {
                context: 'edit_order_route',
                ...optimizationInput,
            });
            this.handleRouteOptimization(result);
        } catch (err) {
            this.notifications.error(err.message ?? this.intl.t('fleet-ops.operations.orders.index.new.route-error'));
        }
    }

    @task *optimizeRoute() {
        const optimizationInput = buildRouteOptimizationInput(this.args.resource);
        const service = this.routeEngine.getOptimizationEngine('osrm');

        try {
            const result = yield this.routeOptimization.optimize(service, {
                context: 'edit_order_route',
                ...optimizationInput,
            });

            this.handleRouteOptimization(result);
        } catch (_err) {
            this.notifications.error(this.intl.t('fleet-ops.operations.orders.index.new.route-error'));
        }
    }

    @action handleRouteOptimization({ sortedWaypoints, route, trip, result, engine = 'osrm' }) {
        applyOptimizedIntermediateWaypoints(this.payload, sortedWaypoints);

        if (route) {
            this.setOptimizedRoute(route, trip, result?.waypoints, engine);
        }

        this.args.resource.set('optimized', true);

        if (typeof this.args.onRouteChanged === 'function') {
            this.args.onRouteChanged();
        }
    }

    @action setOptimizedRoute(route, trip, waypoints, engine = 'osrm') {
        const summary = {
            totalDistance: trip?.distance ?? trip?.totalDistance ?? 0,
            totalTime: trip?.duration ?? trip?.totalTime ?? 0,
        };
        const payload = {
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
