import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { later } from '@ember/runloop';
import { task, timeout } from 'ember-concurrency';
import { OSRMv1, Control as RoutingControl } from '@fleetbase/leaflet-routing-machine';
import getRoutingHost from '@fleetbase/ember-core/utils/get-routing-host';
import engineService from '@fleetbase/ember-core/decorators/engine-service';
import registerComponent from '../../utils/register-component';
import OrderProgressCardComponent from '../order-progress-card';
import LeafletTrackingMarkerComponent from '../leaflet-tracking-marker';
import DisplayPlaceComponent from '../display-place';

function removeParamFromCurrentUrl(paramToRemove) {
    const url = new URL(window.location.href);
    url.searchParams.delete(paramToRemove);
    window.history.pushState({ path: url.href }, '', url.href);
}

function addParamToCurrentUrl(paramName, paramValue) {
    const url = new URL(window.location.href);
    url.searchParams.set(paramName, paramValue);
    window.history.pushState({ path: url.href }, '', url.href);
}

function registerTrackingMarker(owner, componentClass) {
    let emberLeafletService = owner.lookup('service:ember-leaflet');

    if (emberLeafletService) {
        const alreadyRegistered = emberLeafletService.components.find((registeredComponent) => registeredComponent.name === 'leaflet-tracking-marker');
        if (alreadyRegistered) {
            return;
        }
        // we then invoke the `registerComponent` method
        emberLeafletService.registerComponent('leaflet-tracking-marker', {
            as: 'tracking-marker',
            component: componentClass,
        });
    }
}

const MAP_TARGET_FOCUS_PADDING_BOTTOM_RIGHT = [200, 0];
const MAP_TARGET_FOCUS_REFOCUS_PANBY = [150, 0];
export default class CustomerOrdersComponent extends Component {
    @service store;
    @service notifications;
    @service currentUser;
    @service universe;
    @service urlSearchParams;
    @service socket;
    @engineService('@fleetbase/fleetops-engine') movementTracker;
    @tracked orders = [];
    @tracked selectedOrder;
    @tracked zoom = 12;
    @tracked map;
    @tracked mapReady = false;
    @tracked route;
    @tracked query;
    @tracked tileSourceUrl = 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png';

    constructor(owner) {
        super(...arguments);
        this.movementTracker.socket = this.socket;
        registerComponent(owner, OrderProgressCardComponent);
        registerComponent(owner, DisplayPlaceComponent);
        registerTrackingMarker(owner, LeafletTrackingMarkerComponent);
        this.loadCustomerOrders.perform();
    }

    @task *loadCustomerOrders() {
        const query = this.urlSearchParams.get('query');
        this.query = query;

        try {
            if (query) {
                this.orders = yield this.store.query('order', { query });
            } else {
                this.orders = yield this.store.findAll('order');
            }
            this.restoreSelectedOrder();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *searchOrders({ target }) {
        const query = target.value;
        addParamToCurrentUrl('query', query);
        this.unselectOrder();

        yield timeout(300);

        try {
            this.orders = yield this.store.query('order', { query });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action viewOrder(order) {
        this.selectedOrder = order;
        this.resetOrderRoute();
        addParamToCurrentUrl('order', order.public_id);
        const driverCurrentLocation = order.get('trackerData.driver_current_location');
        if (driverCurrentLocation) {
            this.latitude = driverCurrentLocation.coordinates[1];
            this.longitude = driverCurrentLocation.coordinates[0];
            this.mapReady = true;
        }
    }

    @action unselectOrder() {
        this.selectedOrder = null;
        removeParamFromCurrentUrl('order');
    }

    @action onTrackerDataLoaded(order) {
        if (this.selectedOrder && this.selectedOrder.id === order.id) {
            this.viewOrder(order);
        }
    }

    @action setupMap({ target }) {
        this.map = target;
        if (!this.routeControl) {
            this.displayOrderRoute();
        }
    }

    @action displayOrderRoute() {
        const waypoints = this.getRouteCoordinatesFromOrder(this.selectedOrder);
        const routingHost = getRoutingHost();
        if (this.cannotRouteWaypoints(waypoints)) {
            return;
        }

        // center on first coordinate
        this.map.stop();
        this.map.flyTo(waypoints.firstObject);

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
        }).addTo(this.map);

        this.routeControl.on('routesfound', (event) => {
            const { routes } = event;

            this.route = routes.firstObject;
        });

        later(
            this,
            () => {
                this.map.flyToBounds(waypoints, {
                    paddingBottomRight: MAP_TARGET_FOCUS_PADDING_BOTTOM_RIGHT,
                    maxZoom: 14,
                    animate: true,
                });
                this.map.once('moveend', () => {
                    this.map.panBy(MAP_TARGET_FOCUS_REFOCUS_PANBY);
                });
            },
            300
        );
    }

    @action resetOrderRoute() {
        const { routeControl } = this;
        if (routeControl instanceof RoutingControl) {
            try {
                routeControl.remove();
            } catch (e) {
                // silent
            }
        }

        this.displayOrderRoute();
    }

    @action startTrackingDriverPosition(event) {
        const { target } = event;
        const driver = this.selectedOrder.driver;
        if (driver) {
            driver.set('_layer', target);
            this.movementTracker.track(driver);
        }
    }

    @action locateDriver() {
        const driver = this.selectedOrder.driver;
        if (driver) {
            this.map.flyTo(driver.coordinates, 14, {
                paddingBottomRight: MAP_TARGET_FOCUS_PADDING_BOTTOM_RIGHT,
                maxZoom: 14,
                animate: true,
            });
            this.map.once('moveend', () => {
                this.map.panBy(MAP_TARGET_FOCUS_REFOCUS_PANBY);
            });
        }
    }

    @action locateOrderRoute() {
        if (this.selectedOrder) {
            const waypoints = this.getRouteCoordinatesFromOrder(this.selectedOrder);
            this.map.flyToBounds(waypoints, {
                paddingBottomRight: MAP_TARGET_FOCUS_PADDING_BOTTOM_RIGHT,
                maxZoom: 14,
                animate: true,
            });
            this.map.once('moveend', () => {
                this.map.panBy(MAP_TARGET_FOCUS_REFOCUS_PANBY);
            });
        }
    }

    cannotRouteWaypoints(waypoints = []) {
        return !this.map || !isArray(waypoints) || waypoints.length < 2;
    }

    getRouteCoordinatesFromOrder(order) {
        const payload = order.payload;
        const waypoints = [];
        const coordinates = [];

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

    restoreSelectedOrder() {
        const selectedOrderId = this.urlSearchParams.get('order');
        if (selectedOrderId) {
            const selectedOrder = this.orders.find((order) => order.public_id === selectedOrderId);
            if (selectedOrder) {
                this.viewOrder(selectedOrder);
            }
        }
    }
}
