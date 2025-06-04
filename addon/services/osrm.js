import RouteOptimizationInterfaceService from './route-optimization-interface';
import getRoutingHost from '@fleetbase/ember-core/utils/get-routing-host';
import polyline from '@fleetbase/ember-core/utils/polyline';
import { debug } from '@ember/debug';

export default class OsrmService extends RouteOptimizationInterfaceService {
    name = 'OSRM';

    async optimize({ order, payload, waypoints, coordinates: originalCoords }, options = {}) {
        const driverAssigned = order.driver_assigned;
        const driverPosition = driverAssigned?.location?.coordinates; // [lon,lat] | undefined
        const coordinates = driverPosition ? [driverPosition, ...originalCoords] : [...originalCoords];
        const hasDriverStart = Boolean(driverPosition);
        const source = 'first';
        const destination = 'any';
        const roundtrip = false; // don’t loop back
        const routingHost = getRoutingHost(payload, waypoints);

        try {
            const result = await this.fetch.routing(coordinates, { source, destination, roundtrip, annotations: true }, { host: routingHost, ...options });

            // Pair each OSRM waypoint with its Waypoint model
            const modelsByInputIndex = hasDriverStart ? [null, ...waypoints] : waypoints;
            const pairs = result.waypoints.map((wp, idx) => ({
                model: modelsByInputIndex[idx], // Ember model or null (driver)
                wp,
            }));

            // Drop the driver start if present
            const payloadPairs = hasDriverStart ? pairs.slice(1) : pairs;

            // Sort by the optimised order
            payloadPairs.sort((a, b) => a.wp.waypoint_index - b.wp.waypoint_index);

            // Extract the Ember models (null-safe)
            const sortedWaypoints = payloadPairs.map((p) => p.model).filter(Boolean);
            const trip = result.trips?.[0];
            const route = polyline.decode(trip.geometry);

            return { sortedWaypoints, trip, route, result, engine: 'osrm' };
        } catch (err) {
            debug(`[OSRM] Error routing trip : ${err.message}`);
            throw err;
        }
    }
}
