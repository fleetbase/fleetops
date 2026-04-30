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

        return routePoints.map((routePoint) => ({
            routePoint,
            place: routePoint.place,
            ...describeRoutePoint(routePoint, this.routeColor),
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

    @action toggleWaypointsCollapse() {
        this.isWaypointsCollapsed = !this.isWaypointsCollapsed;
    }
}
