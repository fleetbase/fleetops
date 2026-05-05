import hasValidCoordinates from './has-valid-coordinates';

export function waypointToPlace(waypoint) {
    if (!waypoint) {
        return null;
    }

    return waypoint.place ?? waypoint;
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
        points.push({ role: 'pickup', place: payload.pickup });
    }

    waypoints.forEach((waypoint, index) => {
        const place = waypointToPlace(waypoint);

        if (hasValidCoordinates(place)) {
            points.push({ role: 'waypoint', place, stopNumber: index + 1 });
        }
    });

    if (hasValidCoordinates(payload.dropoff)) {
        points.push({ role: 'dropoff', place: payload.dropoff });
    }

    return points;
}

export function describeRoutePoint(routePoint, routeColor) {
    const role = routePoint?.role;
    const stopNumber = routePoint?.stopNumber ?? 1;
    const label = role === 'pickup' ? 'P' : role === 'dropoff' ? 'D' : String(stopNumber);
    const markerColor = role === 'pickup' ? '#22C55E' : role === 'dropoff' ? '#EF4444' : routeColor;
    const title = role === 'pickup' ? 'Pickup' : role === 'dropoff' ? 'Dropoff' : `Stop ${stopNumber}`;

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
    const zIndexOffset = role === 'pickup' ? 2200 : role === 'dropoff' ? 2100 : 1800;

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
