import Component from '@glimmer/component';
import { Control as RoutingControl } from '@fleetbase/leaflet-routing-machine';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class OrderFormRouteComponent extends Component {
    @service leafletMapManager;
    @service leafletRoutingControl;
    @service routeOptimization;
    @service osrm;
    @service location;
    @service store;
    @service currentUser;
    @service notifications;
    @service placeActions;
    @tracked multipleWaypoints = false;
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

        // if (this.isUsingIntegratedVendor) {
        //     this.getQuotes();
        // }
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
        // if (this.isUsingIntegratedVendor) {
        //     this.getQuotes();
        // }
    }

    @action editPlace(place) {
        this.placeActions.modal.edit(place);
    }

    @action previewRoute() {
        this.resetRoutingControl();
        if (!this.coordinates.length) return;

        const routingService = this.currentUser.getOption('routing', { router: 'osrm' }).router;
        const { router, formatter } = this.leafletRoutingControl.get(routingService);

        this.routingControl = new RoutingControl({
            router,
            formatter,
            waypoints: this.coordinates,
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
        }).addTo(this.leafletMapManager.map);

        this.routingControl.on('routesfound', (event) => {
            this.leafletMapManager.route = event;
            this.setRoute(event.routes.firstObject);
        });

        if (this.coordinates.length === 1) {
            this.leafletMapManager.map.flyTo(this.coordinates[0], 18);
            this.leafletMapManager.map.once('moveend', () => {
                this.leafletMapManager.map.panBy([200, 0]);
            });
        } else {
            this.leafletMapManager.map.flyToBounds(this.coordinates, {
                paddingBottomRight: [300, 0],
                maxZoom: this.coordinates.length === 2 ? 15 : 14,
                animate: true,
            });
            this.leafletMapManager.map.once('moveend', () => {
                this.leafletMapManager.map.panBy([150, 0]);
            });
        }
    }

    resetRoutingControl() {
        this.leafletMapManager.removeRoutingControl(this.routingControl);
        this.leafletMapManager.removeRoute();
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
        // Update map layers & UI
        this.resetRoutingControl();

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
