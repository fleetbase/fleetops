import { isArray } from '@ember/array';
import leafletWrapCoordinates from './leaflet-wrap-coordinates';

/**
 * Converts a latitude and longitude pair to a projected CRS point using Leaflet's projection.
 *
 * @param {number} lat - The latitude value.
 * @param {number} lng - The longitude value.
 * @param {Object} [crs=L.CRS.EPSG3857] - The coordinate reference system to use (defaults to EPSG:3857).
 * @returns {L.Point} The projected CRS point as an instance of L.Point.
 */
export function latLngToCRS(lat, lng, crs = L.CRS.EPSG3857) {
    const latLng = L.latLng(lat, lng);
    const point = crs.project(latLng);
    return crs.unproject(point);
}

/**
 * Recursively converts an array of geographic coordinates in GeoJSON order ([lng, lat, ...])
 * to Leaflet's projected CRS coordinates.
 *
 * This function expects that the input `coords` is an array containing coordinate arrays
 * in GeoJSON order. For example:
 *
 *   - A single coordinate: [lng, lat, ...]
 *   - A line string or ring: an array of coordinates, e.g., [[lng, lat], [lng, lat], ...]
 *   - A nested array for polygons or multipolygons.
 *
 * The conversion process involves two steps:
 *
 * 1. **Wrapping:**
 *    The coordinates are first passed through `leafletWrapCoordinates` to ensure that
 *    all longitude values are normalized to the canonical range of [-180, 180].
 *
 * 2. **Projection:**
 *    The geographic coordinates are then converted to a projected CRS using `latLngToCRS`.
 *
 * Depending on the structure of the input, the function returns:
 *
 *   - A single projected point (L.Point) if a single coordinate array is provided.
 *   - An array of projected points if an array of coordinates (e.g., line string) is provided.
 *   - A nested array of projected points for polygons or multipolygons.
 *
 * @param {*} coords - An array of coordinate arrays in GeoJSON order ([lng, lat, ...]).
 *                     This can be a single coordinate, an array of coordinates, or a nested array.
 * @returns {*} The coordinate(s) converted to the projected CRS, matching the structure of the input.
 *
 * @example
 * // Converting a single coordinate:
 * const projectedPoint = unwrapCoordinates([ -80, 26 ]);
 *
 * @example
 * // Converting a line string (ring):
 * const projectedLine = unwrapCoordinates([
 *   [ -80, 26 ],
 *   [ -80.1, 26.1 ],
 *   [ -80.2, 26.2 ]
 * ]);
 *
 * @example
 * // Converting a polygon (array of rings):
 * const projectedPolygon = unwrapCoordinates([
 *   [
 *     [ -80, 26 ],
 *     [ -80.1, 26.1 ],
 *     [ -80.2, 26.2 ],
 *     [ -80, 26 ]
 *   ]
 * ]);
 */
export function unwrapCoordinates(coords) {
    if (!isArray(coords)) {
        // If it's not an array, return it as-is.
        return coords;
    }

    // Ensure coordinates are wrapped properly to the canonical [-180, 180] range.
    coords = leafletWrapCoordinates(coords);

    // If the first element is a number, assume this is a single coordinate [lng, lat, ...].
    if (typeof coords[0] === 'number') {
        // Call leafletWrapCoordinates again for safety (though it should already be wrapped).
        coords = leafletWrapCoordinates(coords);
        // Note: Since our input is in GeoJSON order ([lng, lat]), we convert by swapping the order.
        return latLngToCRS(coords[1], coords[0]);
    }

    // If the first element is an array and its first element is a number,
    // assume this is an array of coordinates (e.g., a line string or ring).
    if (isArray(coords[0]) && typeof coords[0][0] === 'number') {
        return coords.map((c) => latLngToCRS(c[1], c[0]));
    }

    // Otherwise, assume it's a nested array (e.g., for polygons or multipolygons)
    return coords.map(unwrapCoordinates);
}

export default unwrapCoordinates;
