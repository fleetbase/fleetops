import hasValidCoordinates from './has-valid-coordinates';

export function waypointToPlace(waypoint) {
    if (!waypoint) {
        return null;
    }

    return waypoint.place ?? waypoint;
}

export function endpointTrackingNumberUuid(payload, role) {
    if (!payload || !role) {
        return null;
    }

    return payload[`${role}_tracking_number_uuid`] ?? null;
}

export function waypointTrackingNumberUuid(waypoint) {
    return waypoint?.tracking_number_uuid ?? waypoint?.tracking_number?.uuid ?? waypoint?.trackingNumber?.uuid ?? null;
}

export function buildPlaceAddressTooltip(title, place) {
    if (!place) {
        return title;
    }

    const name = place.name && place.name !== place.street1 ? place.name : null;
    const cityStatePostalCode = [place.city, place.province, place.postal_code].filter(Boolean).join(', ');
    const lines = [
        name ? `<div class="fleetops-google-hover-tooltip__meta">${name}</div>` : '',
        place.street1 ? `<div class="fleetops-google-hover-tooltip__meta">${place.street1}</div>` : '',
        place.street2 ? `<div class="fleetops-google-hover-tooltip__meta">${place.street2}</div>` : '',
        cityStatePostalCode ? `<div class="fleetops-google-hover-tooltip__meta">${cityStatePostalCode}</div>` : '',
        place.country_name ? `<div class="fleetops-google-hover-tooltip__meta">${place.country_name}</div>` : '',
    ].filter(Boolean);

    return `
        <div class="fleetops-google-hover-tooltip__title">${title}</div>
        ${lines.join('')}
    `;
}

export function buildRoutePointsFromPayload(payload) {
    if (!payload) {
        return [];
    }

    const points = [];
    const waypoints = payload.waypoints ?? [];

    if (hasValidCoordinates(payload.pickup)) {
        points.push({ role: 'pickup', place: payload.pickup, trackingNumberUuid: endpointTrackingNumberUuid(payload, 'pickup') });
    }

    waypoints.forEach((waypoint, index) => {
        const place = waypointToPlace(waypoint);

        if (hasValidCoordinates(place)) {
            points.push({ role: 'waypoint', place, stopNumber: index + 1, trackingNumberUuid: waypointTrackingNumberUuid(waypoint) });
        }
    });

    if (hasValidCoordinates(payload.dropoff)) {
        points.push({ role: 'dropoff', place: payload.dropoff, trackingNumberUuid: endpointTrackingNumberUuid(payload, 'dropoff') });
    }

    if (hasValidCoordinates(payload.return)) {
        points.push({ role: 'return', place: payload.return, trackingNumberUuid: endpointTrackingNumberUuid(payload, 'return') });
    }

    return points;
}

export function buildServiceStopsFromPayload(payload) {
    if (!payload) {
        return [];
    }

    const stops = [];
    const waypoints = payload.waypoints ?? [];

    if (payload.pickup) {
        stops.push({ role: 'pickup', place: payload.pickup, trackingNumberUuid: endpointTrackingNumberUuid(payload, 'pickup') });
    }

    waypoints.forEach((waypoint, index) => {
        const place = waypointToPlace(waypoint);

        if (place) {
            stops.push({ role: 'waypoint', place, stopNumber: index + 1, trackingNumberUuid: waypointTrackingNumberUuid(waypoint) });
        }
    });

    if (payload.dropoff) {
        stops.push({ role: 'dropoff', place: payload.dropoff, trackingNumberUuid: endpointTrackingNumberUuid(payload, 'dropoff') });
    }

    if (payload.return) {
        stops.push({ role: 'return', place: payload.return, trackingNumberUuid: endpointTrackingNumberUuid(payload, 'return') });
    }

    return stops;
}

export function describeRoutePoint(routePoint, routeColor) {
    const role = routePoint?.role;
    const stopNumber = routePoint?.stopNumber ?? 1;
    const label = role === 'pickup' ? 'P' : role === 'dropoff' ? 'D' : role === 'return' ? 'R' : String(stopNumber);
    const markerColor = role === 'pickup' ? '#22C55E' : role === 'dropoff' ? '#EF4444' : role === 'return' ? '#F97316' : routeColor;
    const title = role === 'pickup' ? 'Pickup' : role === 'dropoff' ? 'Dropoff' : role === 'return' ? 'Return' : `Stop ${stopNumber}`;

    return {
        label,
        markerColor,
        title,
    };
}

export function buildRoutePointMarkerPresentation(routePoint, routeColor, options = {}) {
    const role = routePoint?.role;
    const place = routePoint?.place;
    const { label, markerColor, title } = describeRoutePoint(routePoint, routeColor);
    const zIndexOffset = role === 'pickup' ? 2200 : role === 'dropoff' ? 2100 : role === 'return' ? 2000 : 1800;

    return {
        waypointLabel: label,
        waypointColor: markerColor,
        title,
        zIndexOffset: options.zIndexOffset ?? zIndexOffset,
        tooltip: buildPlaceAddressTooltip(title, place),
        tooltipOptions: {
            direction: 'top',
            offset: [0, -20],
            className: 'fleetops-waypoint-tooltip',
            html: true,
            ...(options.tooltipOptions ?? {}),
        },
    };
}
