/**
 * Route Colors Utility
 *
 * Provides deterministic color assignment, color manipulation helpers,
 * and status-based route line styling for the FleetOps map visualization.
 */

/**
 * The canonical route color palette used across the live map and order detail views.
 * Colors are chosen for high contrast against both light and dark CartoDB tile layers.
 */
export const ROUTE_COLOR_PALETTE = ['#0EA5E9', '#8B5CF6', '#F59E0B', '#10B981', '#F97316', '#EC4899', '#06B6D4', '#EF4444', '#84CC16', '#6366F1'];
export const ORDER_ROUTE_STATUS_COLORS = {
    created: '#2563EB',
    dispatched: '#4F46E5',
    started: '#0891B2',
    enroute: '#CA8A04',
    driver_enroute: '#CA8A04',
    completed: '#16A34A',
    canceled: '#DC2626',
};

/**
 * Darken a hex color by a given integer amount (0-255 per channel).
 *
 * @param {string} hex  - A CSS hex color string, e.g. "#0EA5E9"
 * @param {number} amount - How much to subtract from each RGB channel
 * @returns {string} Darkened hex color string
 */
export function darkenColor(hex, amount = 40) {
    const clean = hex.replace('#', '');
    const num = parseInt(clean, 16);
    const r = Math.max(0, (num >> 16) - amount);
    const g = Math.max(0, ((num >> 8) & 0xff) - amount);
    const b = Math.max(0, (num & 0xff) - amount);
    return `#${[r, g, b].map((v) => v.toString(16).padStart(2, '0')).join('')}`;
}

/**
 * Derive a deterministic color from a string identifier (e.g. order public_id).
 * The same ID will always produce the same color from the palette.
 *
 * @param {string} id - Any string identifier
 * @returns {string} A hex color string from ROUTE_COLOR_PALETTE
 */
export function colorForId(id = '') {
    let hash = 0;
    for (let i = 0; i < id.length; i++) {
        hash = (hash << 5) - hash + id.charCodeAt(i);
        hash |= 0; // Convert to 32-bit integer
    }
    return ROUTE_COLOR_PALETTE[Math.abs(hash) % ROUTE_COLOR_PALETTE.length];
}

export function normalizeOrderRouteStatus(status = 'created') {
    switch (status) {
        case 'pending':
            return 'created';
        case 'driver-enroute':
            return 'driver_enroute';
        case 'cancelled':
            return 'canceled';
        default:
            return status && ORDER_ROUTE_STATUS_COLORS[status] ? status : 'created';
    }
}

export function routeColorForStatus(status = 'created') {
    return ORDER_ROUTE_STATUS_COLORS[normalizeOrderRouteStatus(status)] ?? ORDER_ROUTE_STATUS_COLORS.created;
}

/**
 * Return a Leaflet Path options `styles` array for a route polyline
 * based on the order status. Uses a two-layer "cased" approach:
 * a darker, heavier outline beneath a brighter, thinner main line.
 *
 * @param {string} status - The order status string
 * @param {string} color  - The base hex color for this route
 * @returns {Array<Object>} Array of Leaflet Path options objects
 */
export function routeStyleForStatus(status, fallbackColor = null) {
    const normalizedStatus = normalizeOrderRouteStatus(status);
    const color = routeColorForStatus(normalizedStatus) ?? fallbackColor ?? ORDER_ROUTE_STATUS_COLORS.created;
    const darker = darkenColor(color, 36);

    switch (normalizedStatus) {
        case 'canceled':
            return [
                { color: darkenColor(color, 18), weight: 5, opacity: 0.16, lineCap: 'round', lineJoin: 'round' },
                { color, weight: 3, opacity: 0.52, lineCap: 'round', lineJoin: 'round' },
            ];

        case 'completed':
            return [
                { color: darker, weight: 6, opacity: 0.4, lineCap: 'round', lineJoin: 'round' },
                { color, weight: 3, opacity: 0.8, lineCap: 'round', lineJoin: 'round' },
            ];

        case 'created':
        case 'dispatched':
        case 'started':
        case 'enroute':
        case 'driver_enroute':
        default:
            return [
                { color: darker, weight: 7, opacity: 0.82, lineCap: 'round', lineJoin: 'round' },
                { color, weight: 4, opacity: 1, lineCap: 'round', lineJoin: 'round' },
            ];
    }
}

/**
 * Build a Leaflet `L.divIcon` HTML string for a numbered/typed waypoint marker.
 *
 * @param {string|number} label    - The label to display inside the circle (e.g. "P", "D", "2")
 * @param {string}        bgColor  - CSS background color for the circle
 * @returns {string} HTML string suitable for use in `L.divIcon({ html: ... })`
 */
export function waypointIconHtml(label, bgColor) {
    return `<div style="
        width:32px;height:32px;
        background:${bgColor};
        border:2.5px solid #fff;
        border-radius:50%;
        display:flex;align-items:center;justify-content:center;
        color:#fff;font-weight:700;font-size:13px;
        box-shadow:0 2px 8px rgba(0,0,0,0.45);
        font-family:ui-sans-serif,system-ui,sans-serif;
    ">${label}</div>`;
}
