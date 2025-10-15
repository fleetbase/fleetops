import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency';
import { Point } from '@fleetbase/fleetops-data/utils/geojson';

export default class OrderRouteEditorComponent extends Component {
    @service store;
    @service intl;
    @service notifications;
    @service placeActions;
    @service routeOptimization;
    @service osrm;
    @tracked route;

    get coordinates() {
        const payload = this.args.resource.payload;
        const waypoints = payload ? [payload.pickup, ...this.args.resource.payload.waypoints.map((waypoint) => waypoint.place), payload.dropoff] : [];

        return waypoints.filter((wp) => wp && wp.hasValidCoordinates).map((wp) => [wp.latitude, wp.longitude]);
    }

    @action toggleMultiDropOrder(isMultiDrop) {
        const { pickup, dropoff } = this.args.resource.payload;

        if (isMultiDrop) {
            // if pickup move it to multipdrop
            if (pickup) {
                this.#addWaypointFromExistingPlace(pickup);
            }

            // if pickup move it to multipdrop
            if (dropoff) {
                this.#addWaypointFromExistingPlace(dropoff);
            }

            this.args.resource.payload.setProperties({
                pickup: null,
                dropoff: null,
                return: null,
                pickup_uuid: null,
                dropoff_uuid: null,
                return_uuid: null,
            });
        } else {
            // get pickup from payload waypoints if available
            const waypoints =
                typeof this.args.resource.payload.waypoints.toArray === 'function' ? this.args.resource.payload.waypoints.toArray() : Array.from(this.args.resource.payload.waypoints);

            if (waypoints[0]) {
                const pickup = this.#createPlaceFromWaypoint(waypoints[0]);
                this.args.resource.payload.set('pickup', pickup);
            }

            if (waypoints[1]) {
                const dropoff = this.#createPlaceFromWaypoint(waypoints[1]);
                this.args.resource.payload.set('dropoff', dropoff);
            }

            this.args.resource.payload.setProperties({
                waypoints: [],
            });
        }

        if (typeof this.args.onRouteChanged === 'function') {
            this.args.onRouteChanged();
        }
    }

    @action removeWaypoint(waypoint) {
        this.args.resource.payload.waypoints.removeObject(waypoint);

        if (typeof this.args.onWaypointRemoved === 'function') {
            this.args.onWaypointRemoved(waypoint);
        }

        if (typeof this.args.onRouteChanged === 'function') {
            this.args.onRouteChanged();
        }
    }

    @action addWaypoint() {
        const location = new Point(0, 0);
        const place = this.store.createRecord('place', { location });
        const waypoint = this.store.createRecord('waypoint', { place, location });

        this.args.resource.payload.waypoints.pushObject(waypoint);

        if (typeof this.args.onWaypointAdded === 'function') {
            this.args.onWaypointAdded(waypoint);
        }
    }

    @action setWaypointPlace(index, place) {
        if (isArray(this.args.resource.payload.waypoints) && !this.args.resource.payload.waypoints.objectAt(index)) return;

        const json = place.serialize();
        this.args.resource.payload.waypoints.objectAt(index).setProperties({
            uuid: place.id,
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
        this.args.resource.payload.set(prop, place);

        if (typeof this.args.onPlaceSelected === 'function') {
            this.args.onPlaceSelected(place);
        }

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
        const order = this.args.resource;
        const payload = order.payload;
        const waypoints = this.args.resource.payload.waypoints;
        const coordinates = this.coordinates.map((coord) => coord.reverse());

        try {
            const result = yield this.routeOptimization.optimize(service, {
                context: 'edit_order_route',
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

        try {
            const result = yield this.osrm.optimize({
                context: 'edit_order_route',
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
        // this.previewRoute();
        this.args.resource.set('optimized', true);
    }

    @action setOptimizedRoute(route, trip, waypoints, engine = 'osrm') {
        let summary = { totalDistance: trip.distance, totalTime: trip.duration };
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

    #createPlaceFromWaypoint(waypoint) {
        const json = waypoint.serialize();
        return this.store.createRecord('place', json);
    }

    #addWaypointFromExistingPlace(place) {
        const json = place.serialize();
        const waypoint = this.store.createRecord('waypoint', {
            uuid: place.id,
            place_uuid: place.id,
            location: place.location,
            place,
            ...json,
        });
        this.args.resource.payload.waypoints.pushObject(waypoint);

        if (typeof this.args.onWaypointAdded === 'function') {
            this.args.onWaypointAdded(waypoint);
        }
    }
}
