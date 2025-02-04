import { helper } from '@ember/component/helper';
import leafletUnwrapCoordinates from '../utils/leaflet-unwrap-coordinates';
import { isArray } from '@ember/array';

/**
 * Ember helper to "unwrap" coordinate arrays for Leaflet usage.
 *
 * This helper converts coordinate data into a form that is compatible with Leaflet’s
 * coordinate reference system. It supports two types of input:
 *
 * 1. **GeoJSON Geometry Object:**
 *    An object with a `coordinates` property. In this case the coordinates are assumed
 *    to already be in GeoJSON order ([lng, lat, ...]). The helper unwraps these
 *    coordinates using `leafletUnwrapCoordinates` and returns a new geometry object
 *    with the unwrapped coordinates.
 *
 * 2. **Array of Coordinates:**
 *    An array of coordinate arrays (e.g. a line string, ring, or polygon) provided in
 *    [lat, lng] order. The helper first converts these to GeoJSON order ([lng, lat])
 *    and then unwraps them using `leafletUnwrapCoordinates`.
 *
 * **Note:**
 * - The unwrapping process adjusts coordinate values to ensure they form a continuous
 *   representation (for example, when dealing with dateline-crossing geometries).
 * - If the input is neither an object with a `coordinates` property nor an array,
 *   it is returned unchanged.
 *
 * @param {Array|Object} input - Either:
 *    - A GeoJSON geometry object with a `coordinates` property, where coordinates are in [lng, lat] order.
 *    - An array of coordinate arrays in [lat, lng] order.
 * @returns {Array|Object} A new geometry object or coordinate array with unwrapped coordinates,
 *                         preserving the structure of the input.
 *
 * @example
 * // Example 1: GeoJSON geometry object input:
 * let geojson = {
 *   type: 'Polygon',
 *   coordinates: [
 *     [[-80, 26], [-80.1, 26.1], [-80.2, 26.2], [-80, 26]]
 *   ]
 * };
 * let result = unwrapCoordinates([geojson]);
 *
 * @example
 * // Example 2: Array of coordinates in [lat, lng] order:
 * let coords = [
 *   [26, -80],
 *   [26.1, -80.1],
 *   [26.2, -80.2]
 * ];
 * let result = unwrapCoordinates([coords]);
 */
export default helper(function unwrapCoordinates([input]) {
    if (!input) {
        return input;
    }

    // If input is an object with a "coordinates" property,
    // assume it is a GeoJSON geometry where coordinates are in [lng, lat] order.
    if (typeof input === 'object' && input.coordinates) {
        const unwrappedCoordinates = leafletUnwrapCoordinates(input.coordinates);
        return {
            ...input,
            coordinates: unwrappedCoordinates,
        };
    }

    // Otherwise, assume input is an array of coordinates.
    // The helper expects these coordinates to be in [lat, lng] order.
    // Convert them to GeoJSON order ([lng, lat]) before unwrapping.
    if (isArray(input) && input.length > 0) {
        if (typeof input[0][0] === 'number') {
            // Input is an array of coordinates in [lat, lng] order.
            input = input.map(([latitude, longitude]) => [longitude, latitude]);
        } else {
            // If the structure is nested (e.g., for polygons or multipolygons),
            // reverse the outer array as a fallback. (This branch can be customized as needed.)
            input = input.reverse();
        }
    }
    const unwrappedCoordinates = leafletUnwrapCoordinates(input);
    return unwrappedCoordinates;
});
