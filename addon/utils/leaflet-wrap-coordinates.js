import { isArray } from '@ember/array';

/**
 * Recursively wraps geographic coordinates so that all longitude values are normalized
 * to the canonical range of [-180, 180] degrees.
 *
 * This function assumes coordinates are provided in GeoJSON order: [lng, lat, ...].
 * It works in two primary scenarios:
 *
 * 1. **Single Coordinate Array:**
 *    If the input is a coordinate point (an array of numbers where the first element
 *    is the longitude and the second is the latitude), the function applies the wrap
 *    formula to the longitude component. Any additional dimensions (such as altitude)
 *    are preserved.
 *
 *    For example:
 *    ```js
 *    // Input: [200, 45]  (200° longitude is out-of-range)
 *    // Output: [-160, 45]
 *    ```
 *
 * 2. **Nested Coordinate Arrays:**
 *    If the input is an array of coordinate points (e.g. a line string, polygon ring, or
 *    multi-ring/multi-polygon structure), the function recursively processes each sub-array.
 *
 *    For example:
 *    ```js
 *    // Input: [[200, 45], [210, 46], [220, 47]]
 *    // Output: [[-160, 45], [-150, 46], [-140, 47]]
 *    ```
 *
 * If the input is not an array, the function returns it unchanged.
 *
 * @param {*} coords - The coordinate or nested array of coordinates to be wrapped.
 *                     Expected format for a coordinate is [lng, lat, ...].
 * @returns {*} A new coordinate or nested array of coordinates with longitudes normalized
 *              to the range [-180, 180]. Non-array inputs are returned as-is.
 *
 * @example
 * // Wrapping a single coordinate:
 * const coord = [200, 45];
 * const wrappedCoord = leafletWrapCoordinates(coord);
 * // wrappedCoord is [-160, 45]
 *
 * @example
 * // Wrapping a polygon ring:
 * const ring = [
 *   [200, 45],
 *   [210, 46],
 *   [220, 47],
 *   [200, 45] // closing the ring
 * ];
 * const wrappedRing = leafletWrapCoordinates(ring);
 * // wrappedRing is [[-160, 45], [-150, 46], [-140, 47], [-160, 45]]
 *
 * @export
 */
export default function leafletWrapCoordinates(coords) {
    // If this is a coordinate point (e.g. [lng, lat, ...])
    if (isArray(coords) && typeof coords[0] === 'number' && coords.length >= 2) {
        const lng = coords[0];
        // Wrap the longitude into [-180, 180]
        const wrappedLng = ((((lng + 180) % 360) + 360) % 360) - 180;
        // Return a new coordinate array preserving any extra dimensions (such as altitude)
        return [wrappedLng, ...coords.slice(1)];
    }
    // Otherwise, assume it's a nested array (e.g. a ring or array of rings)
    else if (isArray(coords)) {
        return coords.map(leafletWrapCoordinates);
    }
    // If it's not an array, just return it.
    return coords;
}
