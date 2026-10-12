import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { next } from '@ember/runloop';
import { decodePolyline } from '../../utils/tracking-live-source';

/**
 * The live map on the tracking page: where the order is collected, the customer's stops,
 * numbered as the stop cards are, and the vehicle while it is on its way to them. Each
 * marker carries a label, so the map reads without a legend.
 *
 * The road line is the tracker's: from the vehicle once it reports, from the first
 * pickup before that, sent only when every stop on it is the customer's. Without one, a
 * dashed line joins the points already on the map in trip order, which gives away
 * nothing the markers don't.
 */
export default class TrackingPageMapComponent extends Component {
    @service intl;

    @tracked expanded = false;

    map = null;
    element = null;

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

    get hasSeveralStops() {
        return (this.data.stops ?? []).length > 1;
    }

    get stops() {
        const stops = [];
        let number = 0;
        for (const stop of this.data.stops ?? []) {
            number += 1;
            if (stop.place?.latitude && stop.place?.longitude) {
                const selected = this.hasSeveralStops && stop.id === this.args.selectedStopId;
                const dimmed = this.hasSeveralStops && !!this.args.selectedStopId && !selected;
                stops.push({
                    ...stop,
                    number,
                    location: [stop.place.latitude, stop.place.longitude],
                    label: stop.place.name || this.intl.t('tracking-page.page.your-stop', { number }),
                    icon: this.stopIcon(number, stop.complete, selected, dimmed),
                });
            }
        }

        return stops;
    }

    get pickups() {
        return (this.data.map?.pickups ?? []).map((pickup) => ({
            ...pickup,
            location: [pickup.latitude, pickup.longitude],
            label: pickup.name ? this.intl.t('tracking-page.page.map-pickup-named', { name: pickup.name }) : this.intl.t('tracking-page.page.map-pickup'),
            icon: this.pickupIcon(pickup.complete),
        }));
    }

    get route() {
        return decodePolyline(this.data.map?.polyline);
    }

    get hasRoute() {
        return this.route.length > 1;
    }

    /**
     * The vehicle, or the first store still to visit, then the pickups and stops still to
     * come.
     */
    get plannedRoute() {
        const points = this.vehicle ? [this.vehicle] : [];
        for (const pickup of this.pickups) {
            if (!pickup.complete) {
                points.push(pickup.location);
            }
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
        const points = [...this.pickups.map((pickup) => pickup.location), ...this.stops.map((stop) => stop.location)];
        if (this.vehicle) {
            points.push(this.vehicle);
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

    pickupIcon(complete) {
        return L.divIcon({
            className: complete ? 'tp-map-marker tp-map-pickup tp-map-pickup-complete' : 'tp-map-marker tp-map-pickup',
            html: `<span class="tp-map-pickup-pin">${complete ? '✓' : ''}</span>`,
            iconSize: [22, 22],
            iconAnchor: [11, 11],
            tooltipAnchor: [0, -13],
        });
    }

    stopIcon(number, complete, selected, dimmed) {
        const classes = ['tp-map-marker', 'tp-map-stop'];
        if (complete) {
            classes.push('tp-map-stop-complete');
        }

        if (selected) {
            classes.push('tp-map-stop-selected');
        }

        if (dimmed) {
            classes.push('tp-map-stop-dimmed');
        }

        return L.divIcon({
            className: classes.join(' '),
            html: `<span class="tp-map-stop-pin"><span class="tp-map-stop-number">${complete ? '✓' : Number(number)}</span></span>`,
            iconSize: [30, 38],
            iconAnchor: [15, 38],
            tooltipAnchor: [0, -40],
        });
    }

    /* ── Full screen ───────────────────────────────────────────────── */

    get fullscreenLabel() {
        return this.intl.t(this.expanded ? 'tracking-page.page.map-exit-fullscreen' : 'tracking-page.page.map-fullscreen');
    }

    @action register(element) {
        this.element = element;
        document.addEventListener('fullscreenchange', this.onFullscreenChange);
        document.addEventListener('keydown', this.onKeydown);
    }

    willDestroy() {
        super.willDestroy(...arguments);
        document.removeEventListener('fullscreenchange', this.onFullscreenChange);
        document.removeEventListener('keydown', this.onKeydown);
    }

    /**
     * The browser's full screen where it has one; otherwise the map covers the window.
     */
    @action toggleFullscreen() {
        if (document.fullscreenElement === this.element) {
            document.exitFullscreen?.();
            return;
        }

        if (this.expanded) {
            this.setExpanded(false);
            return;
        }

        const request = this.element?.requestFullscreen?.();
        if (request) {
            request.catch(() => this.setExpanded(true));
        } else {
            this.setExpanded(true);
        }
    }

    @action onFullscreenChange() {
        this.setExpanded(!!this.element && document.fullscreenElement === this.element);
    }

    @action onKeydown(event) {
        if (event.key === 'Escape' && this.expanded && !document.fullscreenElement) {
            this.setExpanded(false);
        }
    }

    setExpanded(expanded) {
        this.expanded = expanded;
        next(() => {
            if (this.map && !this.isDestroyed) {
                this.map.invalidateSize();
                this.fitRoute();
            }
        });
    }

    /* ── Map ───────────────────────────────────────────────────────── */

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

    /**
     * Bring the stop the customer picked into view.
     */
    @action focusStop() {
        const stop = this.stops.find((candidate) => candidate.id === this.args.selectedStopId);
        if (this.map && stop) {
            this.map.panTo(stop.location, { animate: !this.args.reducedMotion });
        }
    }
}
