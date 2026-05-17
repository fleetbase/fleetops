import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { buildRoutePointsFromPayload, describeRoutePoint } from '../utils/route-visualization';
import { colorForId } from '../utils/route-colors';

export default class RouteListComponent extends Component {
    @tracked isWaypointsCollapsed = true;

    get routeColor() {
        const order = this.args.order;

        return colorForId(order?.public_id ?? order?.id ?? 'order-route');
    }

    get routeStops() {
        const routePoints = buildRoutePointsFromPayload(this.args.order?.payload);

        return routePoints
            .map((routePoint) => ({
                routePoint,
                place: routePoint.place,
                badgeStyle: this.badgeStyleForStop(routePoint),
                trackingStop: this.trackingStopFor(routePoint.place),
                routeLeg: this.routeLegFor(routePoint.place),
                ...describeRoutePoint(routePoint, this.routeColor),
            }))
            .map((stop) => ({
                ...stop,
                etaSeconds: stop.routeLeg?.eta_seconds ?? stop.routeLeg?.duration_in_traffic_s ?? stop.routeLeg?.duration_s ?? this.legacyEtaFor(stop.place),
                etaAt: stop.routeLeg?.eta_at,
                completed: Boolean(stop.trackingStop?.completed),
                active: this.matchesStop(stop.trackingStop, this.args.order?.tracker_data?.active_stop),
            }));
    }

    get firstStop() {
        return this.routeStops[0] ?? null;
    }

    get middleStops() {
        return this.routeStops.slice(1, -1);
    }

    get lastStop() {
        return this.routeStops.length > 1 ? this.routeStops.at(-1) : null;
    }

    get hasExtraStops() {
        return this.routeStops.length > 2;
    }

    get shouldCollapseWaypoints() {
        return this.args.isCollapsible !== false && this.hasExtraStops;
    }

    badgeStyleForStop(routePoint) {
        const { markerColor } = describeRoutePoint(routePoint, this.routeColor);
        const isYellow = markerColor?.toLowerCase?.() === '#facc15' || markerColor?.toLowerCase?.() === '#ca8a04';
        const textColor = isYellow ? '#111827' : '#ffffff';

        return `background-color: ${markerColor}; color: ${textColor};`;
    }

    trackingStopFor(place) {
        const stops = this.args.order?.tracker_data?.stops ?? [];

        return stops.find((stop) => this.matchesPlace(stop, place)) ?? null;
    }

    routeLegFor(place) {
        const legs = this.args.order?.tracker_data?.route?.legs ?? [];

        return legs.find((leg) => this.matchesPlace(leg.stop, place)) ?? null;
    }

    legacyEtaFor(place) {
        return this.args.eta?.[place?.id] ?? this.args.eta?.[place?.uuid] ?? this.args.eta?.[place?.public_id] ?? null;
    }

    matchesPlace(stop, place) {
        if (!stop || !place) {
            return false;
        }

        return stop.uuid === place.uuid || stop.public_id === place.public_id || stop.id === place.id;
    }

    matchesStop(stop, activeStop) {
        if (!stop || !activeStop) {
            return false;
        }

        return stop.uuid === activeStop.uuid || stop.public_id === activeStop.public_id;
    }

    @action toggleWaypointsCollapse() {
        this.isWaypointsCollapsed = !this.isWaypointsCollapsed;
    }
}
