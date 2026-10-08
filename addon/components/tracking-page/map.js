import Component from '@glimmer/component';
import { action } from '@ember/object';
import { decodePolyline } from '../../utils/tracking-live-source';

/**
 * The live map on the tracking page: the customer's stops, where the route starts, the
 * route line when every stop is theirs, and the vehicle while it is on its way to them.
 * It renders below the status text, so the page reads before the map loads.
 */
export default class TrackingPageMapComponent extends Component {
    map = null;

    get data() {
        return this.args.data ?? {};
    }

    get vehicle() {
        const location = this.data.location;

        return location ? [location.latitude, location.longitude] : null;
    }

    get stops() {
        const stops = [];
        for (const stop of this.data.stops ?? []) {
            if (stop.place?.latitude && stop.place?.longitude) {
                stops.push({ ...stop, location: [stop.place.latitude, stop.place.longitude] });
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

    get points() {
        const points = this.stops.map((stop) => stop.location);
        if (this.vehicle) {
            points.push(this.vehicle);
        }

        if (this.hub && points.length < 2) {
            points.push(this.hub);
        }

        return points;
    }

    get center() {
        return this.vehicle ?? this.points[0] ?? [0, 0];
    }

    get vehicleIcon() {
        return L.divIcon({ className: this.args.stale ? 'tp-map-vehicle tp-map-vehicle-stale' : 'tp-map-vehicle', iconSize: [28, 28], iconAnchor: [14, 14] });
    }

    get stopIcon() {
        return L.divIcon({ className: 'tp-map-stop', iconSize: [22, 22], iconAnchor: [11, 22] });
    }

    get hubIcon() {
        return L.divIcon({ className: 'tp-map-hub', iconSize: [16, 16], iconAnchor: [8, 8] });
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

        this.map.fitBounds(L.latLngBounds(this.points), { padding: [36, 36], maxZoom: 15 });
    }

    @action recenter() {
        if (this.map && this.vehicle) {
            this.map.setView(this.vehicle, 15, { animate: !this.args.reducedMotion });
        }
    }
}
