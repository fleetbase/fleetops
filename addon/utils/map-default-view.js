/**
 * Helpers for the company's default live map view.
 *
 * Where the live map opens, in order of precedence:
 *   1. "Fit to my vehicles" is on and at least one vehicle has a position:
 *      the map fits every positioned vehicle once it has loaded.
 *   2. The company has a default centre: the map opens there at the
 *      configured zoom. An admin's explicit choice wins over browser
 *      geolocation and active order destinations.
 *   3. Otherwise the user's location: active order destinations, then
 *      browser geolocation, then the IP lookup.
 *   4. The centre of the company's country (or the country of its timezone).
 *   5. The hardcoded fallback.
 */

/** Zoom used for a company-defined centre when no zoom was configured. */
export const DEFAULT_CENTER_ZOOM = 12;

/** Zoom used when the map falls back to the centre of the company's country. */
export const COUNTRY_CENTER_ZOOM = 4;

/** Closest the map zooms when fitting vehicles, so a single vehicle is not shown at street level. */
export const FIT_VEHICLES_MAX_ZOOM = 15;

export function isLatitude(value) {
    return typeof value === 'number' && Number.isFinite(value) && value >= -90 && value <= 90;
}

export function isLongitude(value) {
    return typeof value === 'number' && Number.isFinite(value) && value >= -180 && value <= 180;
}

export function isZoom(value) {
    return typeof value === 'number' && Number.isInteger(value) && value >= 1 && value <= 20;
}

/**
 * Parse a user-entered coordinate, returning null for blank or invalid input.
 *
 * @param {*} value
 * @return {Number|null}
 */
export function toCoordinate(value) {
    if (value === null || value === undefined || (typeof value === 'string' && value.trim() === '')) {
        return null;
    }

    const number = Number(value);
    return Number.isFinite(number) ? number : null;
}

/**
 * The company's configured default centre, or null when none is set.
 *
 * @param {Object} settings map settings from `fleet-ops/settings/map`
 * @return {{latitude: Number, longitude: Number, zoom: Number}|null}
 */
export function defaultMapCenter(settings = {}) {
    const latitude = toCoordinate(settings?.defaultMapLatitude);
    const longitude = toCoordinate(settings?.defaultMapLongitude);
    if (!isLatitude(latitude) || !isLongitude(longitude)) {
        return null;
    }

    const zoom = toCoordinate(settings?.defaultMapZoom);
    return { latitude, longitude, zoom: isZoom(zoom) ? zoom : DEFAULT_CENTER_ZOOM };
}

/**
 * The centre of the company's country, derived by the server, or null.
 *
 * @param {Object} settings map settings from `fleet-ops/settings/map`
 * @return {{latitude: Number, longitude: Number, zoom: Number}|null}
 */
export function companyCountryCenter(settings = {}) {
    const { latitude, longitude } = settings?.companyMapCenter ?? {};
    if (!isLatitude(latitude) || !isLongitude(longitude)) {
        return null;
    }

    return { latitude, longitude, zoom: COUNTRY_CENTER_ZOOM };
}

/**
 * Extract `{ latitude, longitude }` from a GeoJSON point, a `[lat, lng]` pair
 * or a `{ latitude, longitude }` object. Null Island (0,0) is treated as "no
 * position" because that is what unpositioned records default to.
 *
 * @param {*} location
 * @return {{latitude: Number, longitude: Number}|null}
 */
export function coordinatesOf(location) {
    let latitude;
    let longitude;

    if (Array.isArray(location?.coordinates)) {
        [longitude, latitude] = location.coordinates;
    } else if (Array.isArray(location)) {
        [latitude, longitude] = location;
    } else {
        latitude = location?.latitude;
        longitude = location?.longitude;
    }

    if (!isLatitude(latitude) || !isLongitude(longitude) || (latitude === 0 && longitude === 0)) {
        return null;
    }

    return { latitude, longitude };
}

/**
 * Bounds `[[south, west], [north, east]]` around every record that has a
 * position, or null when none do.
 *
 * @param {Array} records records with a `location` (e.g. live vehicles)
 * @return {Array|null}
 */
export function positionedBounds(records = []) {
    const points = (Array.isArray(records) ? records : []).map((record) => coordinatesOf(record?.location)).filter(Boolean);
    if (points.length === 0) {
        return null;
    }

    const latitudes = points.map((point) => point.latitude);
    const longitudes = points.map((point) => point.longitude);

    return [
        [Math.min(...latitudes), Math.min(...longitudes)],
        [Math.max(...latitudes), Math.max(...longitudes)],
    ];
}
