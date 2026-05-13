import { isArray } from '@ember/array';
import { Point } from '@fleetbase/fleetops-data/utils/geojson';
import { buildRoutePointsFromPayload, waypointToPlace } from './route-visualization';

export function routePointsToCoordinates(routePoints = []) {
    return routePoints
        .map(({ place }) => place)
        .filter((place) => place?.hasValidCoordinates)
        .map((place) => [place.latitude, place.longitude]);
}

export function getPayloadRouteCoordinates(payload) {
    return routePointsToCoordinates(buildRoutePointsFromPayload(payload));
}

export function getPayloadIntermediateWaypoints(payload) {
    const waypoints = payload?.waypoints;

    if (typeof waypoints?.toArray === 'function') {
        return waypoints.toArray();
    }

    return isArray(waypoints) ? waypoints : Array.from(waypoints ?? []);
}

export function hasRouteEndpoints(payload) {
    return Boolean(payload?.pickup || payload?.dropoff);
}

export function canOptimizeIntermediateWaypoints(payload) {
    return getPayloadIntermediateWaypoints(payload).length >= 2;
}

export function buildRouteOptimizationInput(order) {
    const payload = order?.payload;
    const routePoints = buildRoutePointsFromPayload(payload);
    const coordinates = routePointsToCoordinates(routePoints).map(([lat, lng]) => [lng, lat]);
    const waypoints = getPayloadIntermediateWaypoints(payload);

    return {
        order,
        payload,
        coordinates,
        routePoints,
        waypoints,
        preserveEndpoints: hasRouteEndpoints(payload),
    };
}

export function applyOptimizedIntermediateWaypoints(payload, sortedWaypoints = []) {
    if (!payload) {
        return;
    }

    if (typeof payload.setWaypoints === 'function') {
        payload.setWaypoints(sortedWaypoints);
        return;
    }

    payload.waypoints = sortedWaypoints;
}

export function createWaypointRecord(store, properties = {}) {
    const place = properties.place ? waypointToPlace(properties.place) : null;
    const serializedPlace = typeof place?.serialize === 'function' ? place.serialize() : {};

    return store.createRecord('waypoint', {
        type: 'dropoff',
        ...serializedPlace,
        ...properties,
        place: place ?? null,
        place_uuid: properties.place_uuid ?? place?.id ?? null,
        location: properties.location ?? place?.location ?? new Point(0, 0),
    });
}

export default {
    routePointsToCoordinates,
    getPayloadRouteCoordinates,
    getPayloadIntermediateWaypoints,
    hasRouteEndpoints,
    canOptimizeIntermediateWaypoints,
    buildRouteOptimizationInput,
    applyOptimizedIntermediateWaypoints,
    createWaypointRecord,
};
