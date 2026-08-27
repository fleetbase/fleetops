/**
 * Default Leaflet raster tile sources.
 *
 * CARTO's `basemaps.cartocdn.com` raster tiles now require an API key and
 * render an "API key required" watermark without one, so the default basemap
 * is the keyless OpenStreetMap tile server. Operators can point Leaflet at any
 * other XYZ provider (including a keyed CARTO URL) via Fleet-Ops map settings.
 */
export const DEFAULT_LEAFLET_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
export const DEFAULT_LEAFLET_DARK_TILE_URL = DEFAULT_LEAFLET_TILE_URL;
export const DEFAULT_LEAFLET_TILE_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

/**
 * Resolve the Leaflet tile URL for a theme from a map settings object.
 *
 * @param {Object} settings map settings, may contain `leafletTileUrl` and `leafletDarkTileUrl`
 * @param {String} theme 'light' or 'dark'
 * @return {String} the XYZ tile URL template to use
 */
export function getLeafletTileUrl(settings = {}, theme = 'light') {
    const customLight = typeof settings.leafletTileUrl === 'string' ? settings.leafletTileUrl.trim() : '';
    const customDark = typeof settings.leafletDarkTileUrl === 'string' ? settings.leafletDarkTileUrl.trim() : '';

    if (theme === 'dark') {
        return customDark || customLight || DEFAULT_LEAFLET_DARK_TILE_URL;
    }

    return customLight || DEFAULT_LEAFLET_TILE_URL;
}

export default getLeafletTileUrl;
