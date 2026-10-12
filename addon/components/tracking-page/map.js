import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { decodePolyline } from '../../utils/tracking-live-source';

/**
 * The live map on the tracking page: the customer's stops, numbered as the stop cards
 * are, where the route starts, and the vehicle while it is on its way to them. Each
 * marker carries a label, so the map reads without a legend.
 *
 * The road line comes from live routing, which the server sends only while the vehicle
 * is on its way and every stop is the customer's. Until then a dashed line joins the
 * points already on the map, in stop order: it shows the order of the trip and gives
 * away nothing the markers don't.
 */
export default class TrackingPageMapComponent extends Component {
    @service intl;

    map = null;

    get data() {
        return this.args.data ?? {};
    }

    get vehicle() {
        const location = this.data.location;

        return location ? [location.latitude, location.longitude] : null;
    }

    get vehicleLabel() {
        return this.data.driver?.name || this.intl.t('tracking-page.page.map-driver');
    }

    get stops() {
        const stops = [];
        let number = 0;
        for (const stop of this.data.stops ?? []) {
            number += 1;
            if (stop.place?.latitude && stop.place?.longitude) {
                stops.push({
                    ...stop,
                    number,
                    location: [stop.place.latitude, stop.place.longitude],
                    label: stop.place.name || this.intl.t('tracking-page.page.your-stop', { number }),
                    icon: this.stopIcon(number, stop.complete),
                });
            }
        }

        return stops;
    }

    get hub() {
        const hub = this.data.map?.hub;

        return hub ? [hub.latitude, hub.longitude] : null;
    }

    get route() {
        return decodePolyline(this.data.map?.polyline);
    }

    get hasRoute() {
        return this.route.length > 1;
    }

    /**
     * Pickup, then each stop still to come; the vehicle stands in for the pickup once it
     * is on its way.
     */
    get plannedRoute() {
        const points = [];
        const start = this.vehicle ?? this.hub;
        if (start) {
            points.push(start);
        }

        for (const stop of this.stops) {
            if (!stop.complete) {
                points.push(stop.location);
            }
        }

        return points;
    }

    get hasPlannedRoute() {
        return !this.hasRoute && this.plannedRoute.length > 1;
    }

    get points() {
        const points = this.stops.map((stop) => stop.location);
        if (this.vehicle) {
            points.push(this.vehicle);
        }

        if (this.hub) {
            points.push(this.hub);
        }

        return points;
    }

    get center() {
        return this.vehicle ?? this.points[0] ?? [0, 0];
    }

    get vehicleIcon() {
        return L.divIcon({
            className: this.args.stale ? 'tp-map-marker tp-map-vehicle tp-map-vehicle-stale' : 'tp-map-marker tp-map-vehicle',
            html: '<span class="tp-map-vehicle-dot"></span>',
            iconSize: [28, 28],
            iconAnchor: [14, 14],
            tooltipAnchor: [0, -16],
        });
    }

    get hubIcon() {
        return L.divIcon({ className: 'tp-map-marker tp-map-hub', html: '<span class="tp-map-hub-dot"></span>', iconSize: [18, 18], iconAnchor: [9, 9], tooltipAnchor: [0, -11] });
    }

    stopIcon(number, complete) {
        return L.divIcon({
            className: complete ? 'tp-map-marker tp-map-stop tp-map-stop-complete' : 'tp-map-marker tp-map-stop',
            html: `<span class="tp-map-stop-pin"><span class="tp-map-stop-number">${complete ? '✓' : Number(number)}</span></span>`,
            iconSize: [30, 38],
            iconAnchor: [15, 38],
            tooltipAnchor: [0, -40],
        });
    }

    @action setup({ target }) {
        this.map = target;
        this.fitRoute();
    }

    @action fitRoute() {
        if (!this.map || this.points.length === 0) {
            return;
        }

        if (this.points.length === 1) {
            this.map.setView(this.points[0], 14);
            return;
        }

        this.map.fitBounds(L.latLngBounds(this.points), { padding: [48, 48], maxZoom: 15 });
    }

    @action recenter() {
        if (this.map && this.vehicle) {
            this.map.setView(this.vehicle, 15, { animate: !this.args.reducedMotion });
        }
    }
}
