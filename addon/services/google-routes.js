/* global google */
import RouteOptimizationInterfaceService from './route-optimization-interface';
import { isArray } from '@ember/array';
import { inject as service } from '@ember/service';

export default class GoogleRoutesService extends RouteOptimizationInterfaceService {
    @service mapSettings;

    name = 'Google';
    _apiLoadPromise = null;

    async computeRoute(waypoints = [], options = {}) {
        const normalizedWaypoints = this.#normalizeWaypoints(waypoints);
        if (normalizedWaypoints.length < 2) {
            throw new Error('At least 2 waypoints are required to compute a route.');
        }

        await this.#ensureApiLoaded(options);
        const { Route, TravelMode, RoutingPreference } = await google.maps.importLibrary('routes');
        const optimizeWaypointOrder = options.optimizeWaypointOrder === true;
        const request = {
            origin: normalizedWaypoints[0],
            destination: normalizedWaypoints[normalizedWaypoints.length - 1],
            travelMode: TravelMode.DRIVING,
            routingPreference: optimizeWaypointOrder ? RoutingPreference.TRAFFIC_AWARE : RoutingPreference.TRAFFIC_AWARE_OPTIMAL,
            fields: ['path', 'viewport', 'distanceMeters', 'durationMillis', 'legs', 'optimizedIntermediateWaypointIndices'],
        };

        if (normalizedWaypoints.length > 2) {
            request.intermediates = normalizedWaypoints.slice(1, -1).map((waypoint) => ({ location: waypoint, via: false }));
        }

        if (optimizeWaypointOrder) {
            request.optimizeWaypointOrder = true;
        }

        const response = await Route.computeRoutes(request);
        const route = response?.routes?.[0];

        if (!route?.path?.length) {
            throw new Error('Google route computation returned no path.');
        }

        const coordinates = route.path.map((point) => [typeof point.lat === 'function' ? point.lat() : point.lat, typeof point.lng === 'function' ? point.lng() : point.lng]);
        const optimizedIndices = isArray(route.optimizedIntermediateWaypointIndices) ? route.optimizedIntermediateWaypointIndices : [];
        const bounds = this.#extractBounds(route.viewport) ?? this.#boundsFromCoordinates(coordinates);

        return {
            engine: 'google',
            waypoints: this.#reorderWaypoints(waypoints, optimizedIndices),
            coordinates,
            bounds,
            summary: {
                totalDistance: route.distanceMeters ?? 0,
                totalTime: route.durationMillis ? route.durationMillis / 1000 : 0,
            },
            legs: isArray(route.legs)
                ? route.legs.map((leg) => ({
                      distanceMeters: leg.distanceMeters ?? 0,
                      durationMillis: leg.durationMillis ?? 0,
                      startLocation: leg.startLocation,
                      endLocation: leg.endLocation,
                  }))
                : [],
            raw: route,
            optimizedIntermediateWaypointIndices: optimizedIndices,
        };
    }

    async optimize({ order, waypoints, coordinates }, options = {}) {
        const route = await this.computeRoute(this.#normalizeOptimizationCoordinates(order, coordinates), {
            ...options,
            optimizeWaypointOrder: true,
        });
        const sortedWaypoints = isArray(route.optimizedIntermediateWaypointIndices) ? route.optimizedIntermediateWaypointIndices.map((index) => waypoints[index]).filter(Boolean) : waypoints;

        return {
            sortedWaypoints,
            route: route.coordinates,
            trip: {
                distance: route.summary.totalDistance,
                duration: route.summary.totalTime,
                legs: route.legs,
            },
            result: route.raw,
            engine: 'google',
        };
    }

    #normalizeOptimizationCoordinates(order, coordinates = []) {
        const driverPosition = order?.driver_assigned?.location?.coordinates;
        const normalized = (isArray(coordinates) ? coordinates : []).map(([lng, lat]) => [lat, lng]);

        if (isArray(driverPosition) && driverPosition.length >= 2) {
            return [[driverPosition[1], driverPosition[0]], ...normalized];
        }

        return normalized;
    }

    #normalizeWaypoints(waypoints = []) {
        return (isArray(waypoints) ? waypoints : [])
            .map((waypoint) => {
                if (isArray(waypoint) && waypoint.length >= 2) {
                    return { lat: Number(waypoint[0]), lng: Number(waypoint[1]) };
                }

                if (Number.isFinite(waypoint?.lat) && Number.isFinite(waypoint?.lng)) {
                    return { lat: Number(waypoint.lat), lng: Number(waypoint.lng) };
                }

                return null;
            })
            .filter((waypoint) => Number.isFinite(waypoint?.lat) && Number.isFinite(waypoint?.lng));
    }

    #reorderWaypoints(waypoints = [], optimizedIndices = []) {
        if (!isArray(optimizedIndices) || optimizedIndices.length === 0 || waypoints.length < 3) {
            return waypoints;
        }

        const start = waypoints[0];
        const end = waypoints[waypoints.length - 1];
        const intermediates = waypoints.slice(1, -1);
        const orderedIntermediates = optimizedIndices.map((index) => intermediates[index]).filter(Boolean);

        return [start, ...orderedIntermediates, end];
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

    #extractBounds(viewport) {
        if (!viewport) return null;

        if (typeof viewport.getSouthWest === 'function' && typeof viewport.getNorthEast === 'function') {
            const sw = viewport.getSouthWest();
            const ne = viewport.getNorthEast();
            return [
                [sw.lat(), sw.lng()],
                [ne.lat(), ne.lng()],
            ];
        }

        if (Number.isFinite(viewport.south) && Number.isFinite(viewport.west) && Number.isFinite(viewport.north) && Number.isFinite(viewport.east)) {
            return [
                [viewport.south, viewport.west],
                [viewport.north, viewport.east],
            ];
        }

        return null;
    }

    async #ensureApiLoaded(options = {}) {
        if (window.google?.maps?.importLibrary) {
            return true;
        }

        if (this._apiLoadPromise) {
            return this._apiLoadPromise;
        }

        const apiKey = options.apiKey ?? this.mapSettings.googleMapsApiKey;
        if (!apiKey) {
            throw new Error('Google Maps API key is required for Google route computation.');
        }

        this._apiLoadPromise = new Promise((resolve, reject) => {
            const callbackName = `__fleetopsGoogleRoutesInit_${Date.now().toString(36)}`;
            window[callbackName] = () => {
                delete window[callbackName];
                resolve(true);
            };

            const script = document.createElement('script');
            script.async = true;
            script.defer = true;
            script.onerror = (error) => {
                delete window[callbackName];
                reject(error);
            };
            script.src = `https://maps.googleapis.com/maps/api/js?key=${apiKey}&libraries=routes,geometry,marker&callback=${callbackName}&loading=async`;
            document.head.appendChild(script);
        });

        return this._apiLoadPromise;
    }
}
