import RouteOptimizationInterfaceService from './route-optimization-interface';
import { isArray } from '@ember/array';
import getRoutingHost from '@fleetbase/ember-core/utils/get-routing-host';
import polyline from '@fleetbase/ember-core/utils/polyline';
import { debug } from '@ember/debug';

export default class OsrmService extends RouteOptimizationInterfaceService {
    name = 'OSRM';

    async computeRoute(waypoints = [], options = {}) {
        const normalizedWaypoints = (isArray(waypoints) ? waypoints : [])
            .map(([lat, lng]) => [Number(lat), Number(lng)])
            .filter(([lat, lng]) => Number.isFinite(lat) && Number.isFinite(lng));

        if (normalizedWaypoints.length < 2) {
            throw new Error('At least 2 waypoints are required to compute a route.');
        }

        const routingHost = getRoutingHost(options.payload, options.waypoints);
        const coordStr = normalizedWaypoints.map(([lat, lng]) => `${lng},${lat}`).join(';');
        const response = await fetch(`${routingHost}/route/v1/driving/${coordStr}?overview=full&geometries=polyline&steps=true`);

        if (!response.ok) {
            throw new Error(`OSRM route request failed with status ${response.status}`);
        }

        const result = await response.json();
        const route = result?.routes?.[0];
        if (!route?.geometry) {
            throw new Error('OSRM route request returned no geometry.');
        }

        const coordinates = polyline.decode(route.geometry);
        const bounds = this.#boundsFromCoordinates(coordinates);

        return {
            engine: 'osrm',
            waypoints: normalizedWaypoints,
            coordinates,
            bounds,
            summary: {
                totalDistance: route.distance ?? 0,
                totalTime: route.duration ?? 0,
            },
            legs: route.legs ?? [],
            raw: result,
        };
    }

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

    #boundsFromCoordinates(coordinates = []) {
        if (!coordinates.length) {
            return [
                [0, 0],
                [0, 0],
            ];
        }

        const lats = coordinates.map(([lat]) => lat);
        const lngs = coordinates.map(([, lng]) => lng);

        return [
            [Math.min(...lats), Math.min(...lngs)],
            [Math.max(...lats), Math.max(...lngs)],
        ];
    }
}
