import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { later } from '@ember/runloop';
import { task, timeout } from 'ember-concurrency';
import { OSRMv1, Control as RoutingControl } from '@fleetbase/leaflet-routing-machine';
import getRoutingHost from '@fleetbase/ember-core/utils/get-routing-host';
import registerComponent from '../../utils/register-component';
import OrderProgressCardComponent from '../order-progress-card';

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

const MAP_TARGET_FOCUS_PADDING_BOTTOM_RIGHT = [200, 0];
const MAP_TARGET_FOCUS_REFOCUS_PANBY = [150, 0];
export default class CustomerOrdersComponent extends Component {
    @service store;
    @service notifications;
    @service currentUser;
    @service universe;
    @service urlSearchParams;
    @service socket;
    @tracked orders = [];
    @tracked selectedOrder;
    @tracked channels = [];
    @tracked eventBuffer = [];
    @tracked zoom = 12;
    @tracked map;
    @tracked mapReady = false;
    @tracked route;
    @tracked query;
    @tracked tileSourceUrl = 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png';

    constructor(owner) {
        super(...arguments);
        registerComponent(owner, OrderProgressCardComponent);
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

    @action closeChannels() {}

    @action setupMap({ target }) {
        this.map = target;
        this.displayOrderRoute();
    }

    displayOrderRoute() {
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

    @action startTrackingDriverPosition({ target }) {
        console.log('startTrackingDriverPosition()', ...arguments);
        const driver = this.selectedOrder.driver;
        if (driver) {
            driver.set('_layer', target);
            this.trackDriverMovement(driver);
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

    async trackDriverMovement(driver) {
        // Create socket instance
        const socket = this.socket.instance();

        // Listen on the specific channel
        const channelId = `driver.${driver.id}`;
        const channel = socket.subscribe(channelId);

        // Track the channel
        this.channels.pushObject(channel);

        // Listen to the channel for events
        await channel.listener('subscribe').once();

        // Time to wait in milliseconds before processing buffered events
        const bufferTime = 1000 * 10;

        // Start a timer to process the buffer at intervals
        setInterval(() => {
            this.processLocationChangeBuffer.perform(driver);
        }, bufferTime);

        // Get incoming data and console out
        (async () => {
            for await (let output of channel) {
                const { event } = output;

                if (event === `driver.location_changed` || event === `driver.simulated_location_changed`) {
                    // Add the incoming event to the buffer
                    this.eventBuffer.push(output);
                }
            }
        })();
    }

    @task *processLocationChangeBuffer(driver) {
        // Sort events by created_at
        this.eventBuffer = this.eventBuffer.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));

        // Process sorted events
        for (const output of this.eventBuffer) {
            const { event, data } = output;

            // log incoming event
            console.log(`${event} - #${data.additionalData.index} (${output.created_at}) [ ${data.location.coordinates.join(' ')} ]`);

            // get movingObject marker
            const objectMarker = driver._layer;

            if (objectMarker) {
                objectMarker.setLatLng(data.location.coordinates);
                yield timeout(1000);
                // // Update the object's heading degree
                // objectMarker.setRotationAngle(data.heading);
                // // Move the object's marker to new coordinates
                // objectMarker.slideTo(data.location.coordinates, { duration: 2000 });
            }
        }

        // Clear the buffer
        this.eventBuffer.length = 0;
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
