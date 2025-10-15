import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';
import { task } from 'ember-concurrency';

export default class OrderFormRouteComponent extends Component {
    @service leafletMapManager;
    @service routeOptimization;
    @service osrm;
    @service location;
    @service store;
    @service currentUser;
    @service notifications;
    @service placeActions;
    @tracked multipleWaypoints = false;
    @tracked routingControl;
    @tracked route;

    get coordinates() {
        const payload = this.args.resource.payload;
        const waypoints = payload ? [payload.pickup, ...this.args.resource.payload.waypoints.map((waypoint) => waypoint.place), payload.dropoff] : [];

        return waypoints.filter((wp) => wp && wp.hasValidCoordinates).map((wp) => [wp.latitude, wp.longitude]);
    }

    get places() {
        const payload = this.args.resource.payload;
        const waypoints = payload ? [payload.pickup, ...this.args.resource.payload.waypoints.map((waypoint) => waypoint.place), payload.dropoff] : [];

        return waypoints.filter((place) => place);
    }

    willDestroy() {
        super.willDestroy(...arguments);
        if (this.routingControl) {
            this.leafletMapManager.removeRoutingControl(this.routingControl);
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
        if (!this.args.resource.payload.waypoints[index]) {
            return;
        }

        this.args.resource.payload.waypoints[index].place = place;
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
        if (!this.coordinates.length) return;

        const routingControl = await this.leafletMapManager.replaceRoutingControl(this.coordinates, this.routingControl, {
            onRouteFound: (route) => this.setRoute(route),
            removeOptions: {
                filter: (layer) => layer.record_id === this.args.resource.driver_assigned?.id,
            },
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

        try {
            const result = yield this.osrm.optimize({
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
}
