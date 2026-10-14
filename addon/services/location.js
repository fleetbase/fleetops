import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { isBlank } from '@ember/utils';
import { isArray } from '@ember/array';
import { later } from '@ember/runloop';
import { debug } from '@ember/debug';
import { isLatitude, isLongitude, isZoom } from '../utils/map-default-view';

/**
 * Service for managing and retrieving user location data.
 * It interacts with various sources to provide the most accurate location information.
 *
 * @extends Service
 */
const GeolocationPositionError = window.GeolocationPositionError;
export default class LocationService extends Service {
    /**
     * Default latitude used when location data is unavailable.
     * @type {number}
     * @static
     */
    static DEFAULT_LATITUDE = 1.369;

    /**
     * Default longitude used when location data is unavailable.
     * @type {number}
     * @static
     */
    static DEFAULT_LONGITUDE = 103.8864;

    /**
     * Service for accessing the current user's data.
     * @type {CurrentUserService}
     */
    @service currentUser;

    /**
     * A service for managing application-wide events and states.
     * @type {UniverseService}
     */
    @service universe;

    /**
     * Service for making HTTP requests, with support for caching responses.
     * @type {FetchService}
     */
    @service fetch;

    /**
     * Company map settings, which carry the default map view.
     * @type {MapSettingsService}
     */
    @service mapSettings;

    /**
     * Current latitude of the user.
     * @type {number}
     */
    @tracked latitude = LocationService.DEFAULT_LATITUDE;

    /**
     * Current longitude of the user.
     * @type {number}
     */
    @tracked longitude = LocationService.DEFAULT_LONGITUDE;

    /**
     * Zoom suggested by the source of the location (a company default view or
     * a country centre), or null to let the map use its own default.
     * @type {number|null}
     */
    @tracked zoom = null;

    /**
     * Flag indicating whether the user's location has been located.
     * @type {boolean}
     */
    @tracked located = false;

    /**
     * Retrieves the current latitude.
     * @returns {number} The current latitude.
     */
    getLatitude() {
        // Ensure we always return a valid number within geographic bounds
        if (typeof this.latitude === 'number' && !isNaN(this.latitude) && this.latitude >= -90 && this.latitude <= 90) {
            return this.latitude;
        }
        return LocationService.DEFAULT_LATITUDE;
    }

    /**
     * Retrieves the current longitude.
     * @returns {number} The current longitude.
     */
    getLongitude() {
        // Ensure we always return a valid number within geographic bounds
        if (typeof this.longitude === 'number' && !isNaN(this.longitude) && this.longitude >= -180 && this.longitude <= 180) {
            return this.longitude;
        }
        return LocationService.DEFAULT_LONGITUDE;
    }

    /**
     * Retrieves the zoom suggested by the current location source, or null.
     * @returns {number|null}
     */
    getZoom() {
        return isZoom(this.zoom) ? this.zoom : null;
    }

    /**
     * Resolves where maps should open. A company-configured default centre
     * (Settings > Map) wins outright. Otherwise it tries the active order
     * destinations, then the browser's geolocation API, then the IP lookup,
     * then the centre of the company's country, then the hardcoded default.
     * See utils/map-default-view for the full precedence.
     *
     * @returns {Promise<Object>} A promise that resolves to an object containing latitude and longitude.
     */
    async getUserLocation() {
        // If the location has already been located, return the existing coordinates
        if (this.located) {
            return { latitude: this.latitude, longitude: this.longitude };
        }

        // The company's chosen starting point beats any guess about the user.
        await this.mapSettings.load();
        const defaultCenter = this.mapSettings.defaultMapCenter;
        if (defaultCenter) {
            this.updateLocation(defaultCenter);
            return { latitude: defaultCenter.latitude, longitude: defaultCenter.longitude };
        }

        try {
            const coordinates = await this.fetch.cachedGet('fleet-ops/live/coordinates', {}, { expirationInterval: 1, expirationIntervalUnit: 'hour' });

            if (isBlank(coordinates)) {
                return await this.getUserLocationFromNavigator();
            }

            if (isArray(coordinates) && coordinates.length > 0) {
                // Ensure the coordinates array contains valid data
                const validCoordinates = coordinates.find((point) => point.coordinates[0] !== 0 && point.coordinates[1] !== 0);
                if (validCoordinates) {
                    const [longitude, latitude] = validCoordinates.coordinates;
                    this.updateLocation({ latitude, longitude });
                    return { latitude, longitude };
                }
            }

            return await this.getUserLocationFromWhois();
        } catch (error) {
            return await this.getUserLocationFromWhois();
        }
    }

    /**
     * Retrieves the user's location using the browser's navigator geolocation API.
     * If the geolocation is not available, times out, or the user denies permission,
     * it falls back to WHOIS data.
     *
     * @returns {Promise<Object>} A promise that resolves to geolocation coordinates or WHOIS data.
     */
    async getUserLocationFromNavigator() {
        if (window.navigator && window.navigator.geolocation) {
            try {
                const position = await Promise.race([
                    new Promise((resolve, reject) => {
                        navigator.geolocation.getCurrentPosition(
                            (position) => {
                                resolve(position);
                            },
                            (error) => {
                                reject(error);
                            },
                            {
                                enableHighAccuracy: true,
                                timeout: 5000,
                            }
                        );
                    }),
                    new Promise((_, reject) => later(this, () => reject(new Error('Geolocation request timed out')), 7000)),
                ]);

                const { latitude, longitude } = position.coords;
                this.updateLocation({ latitude, longitude });
                return { latitude, longitude };
            } catch (error) {
                // Handle specific geolocation errors
                if (error instanceof GeolocationPositionError) {
                    switch (error.code) {
                        case error.PERMISSION_DENIED:
                            debug('LocationService Error: Geolocation permission denied.');
                            break;
                        case error.POSITION_UNAVAILABLE:
                            debug('LocationService Error: Position unavailable.');
                            break;
                        case error.TIMEOUT:
                            debug('LocationService Error: Geolocation request timed out.');
                            break;
                        default:
                            debug('LocationService Error: An unknown geolocation error occurred.');
                            break;
                    }
                } else {
                    debug('LocationService Error: Geolocation request failed:', error.message);
                }
                return await this.getUserLocationFromWhois();
            }
        } else {
            // Navigator geolocation is not available
            return await this.getUserLocationFromWhois();
        }
    }

    /**
     * Discards the resolved location and resolves it again, e.g. after the
     * company's default map view changed. Listeners of `user.located` (the
     * live map) move to the new location.
     *
     * @returns {Promise<Object>} The newly resolved coordinates.
     */
    async relocate() {
        this.located = false;
        return this.getUserLocation();
    }

    /**
     * Retrieves the user's location based on WHOIS data associated with their account.
     * When the IP lookup failed it falls back to the centre of the company's
     * country, and only then to the predefined coordinates.
     *
     * @returns {Object} An object containing latitude and longitude from WHOIS data or default values.
     */
    getUserLocationFromWhois() {
        const whois = this.currentUser.getOption('whois', {}) ?? {};
        const latitude = Number(whois.latitude);
        const longitude = Number(whois.longitude);
        // The IP lookup's fallback reports null coordinates, which Number() turns into Null Island.
        const hasWhoisCoordinates = whois.latitude != null && whois.longitude != null && isLatitude(latitude) && isLongitude(longitude);

        const location = hasWhoisCoordinates
            ? { latitude, longitude }
            : (this.mapSettings.companyCountryCenter ?? { latitude: LocationService.DEFAULT_LATITUDE, longitude: LocationService.DEFAULT_LONGITUDE });

        this.updateLocation(location);
        return { latitude: location.latitude, longitude: location.longitude };
    }

    /**
     * Updates the service's tracked properties with the new location data.
     * Triggers an event to notify other parts of the application that the user's location has been updated.
     *
     * @param {Object} coordinates - An object containing the latitude and longitude to be set, and optionally a suggested zoom.
     */
    updateLocation({ latitude, longitude, zoom = null }) {
        this.latitude = latitude;
        this.longitude = longitude;
        this.zoom = isZoom(zoom) ? zoom : null;
        this.located = true;
        this.universe.trigger('user.located', { latitude, longitude, zoom: this.zoom });
    }
}
