import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { isBlank } from '@ember/utils';
import { isArray } from '@ember/array';
import getWithDefault from '@fleetbase/ember-core/utils/get-with-default';

/**
 * Service for managing and retrieving user location data.
 * It interacts with various sources to provide the most accurate location information.
 *
 * @extends Service
 */
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
     * Current latitude of the user.
     * @type {number}
     */
    @tracked latitude = this.DEFAULT_LATITUDE;

    /**
     * Current longitude of the user.
     * @type {number}
     */
    @tracked longitude = this.DEFAULT_LONGITUDE;

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
        return this.latitude;
    }

    /**
     * Retrieves the current longitude.
     * @returns {number} The current longitude.
     */
    getLongitude() {
        return this.longitude;
    }

    /**
     * Attempts to fetch the user's location from various sources.
     * Uses cached data, navigator geolocation, or WHOIS data as fallbacks.
     * @returns {Promise<Object>} A promise that resolves to an object containing latitude and longitude.
     */
    getUserLocation() {
        return this.fetch.cachedGet('fleet-ops/live/coordinates', {}, { expirationInterval: 1, expirationIntervalUnit: 'hour' }).then((coordinates) => {
            if (isBlank(coordinates)) {
                return this.getUserLocationFromNavigator().then((navigatorCoordinates) => {
                    this.updateLocation(navigatorCoordinates);
                    return navigatorCoordinates;
                });
            }

            if (isArray(coordinates)) {
                const validCoordinates = coordinates.filter((point) => point.coordinates[0] !== 0);
                if (validCoordinates) {
                    const [longitude, latitude] = getWithDefault(validCoordinates, '0.coordinates', [0, 0]);
                    const userCoordinates = {
                        latitude,
                        longitude,
                    };

                    this.updateLocation(userCoordinates);
                    return userCoordinates;
                }
            }

            return this.getUserLocationFromWhois();
        });
    }

    /**
     * Retrieves the user's location using the browser's navigator geolocation API.
     * @returns {Promise<Object>} A promise that resolves to geolocation coordinates.
     */
    getUserLocationFromNavigator() {
        return new Promise((resolve) => {
            // eslint-disable-next-line no-undef
            if (window.navigator && window.navigator.geolocation) {
                // eslint-disable-next-line no-undef
                return navigator.geolocation.getCurrentPosition(
                    ({ coords }) => {
                        this.updateLocation(coords);
                        return resolve(coords);
                    },
                    () => {
                        // If failed use user whois
                        return resolve(this.getUserLocationFromWhois());
                    }
                );
            }

            // default to whois lookup coordinates
            return resolve(this.getUserLocationFromWhois());
        });
    }

    /**
     * Retrieves the user's location based on WHOIS data associated with the user's account.
     * @returns {Object} An object containing latitude and longitude from WHOIS data.
     */
    getUserLocationFromWhois() {
        const whois = this.currentUser.getOption('whois');
        const coordinates = {
            latitude: getWithDefault(whois, 'latitude', this.DEFAULT_LATITUDE),
            longitude: getWithDefault(whois, 'longitude', this.DEFAULT_LONGITUDE),
        };

        this.updateLocation(coordinates);
        return coordinates;
    }

    /**
     * Updates the service's tracked properties with the new location data and triggers an event.
     * @param {Object} coordinates - An object containing the latitude and longitude.
     */
    updateLocation({ latitude, longitude }) {
        this.latitude = latitude;
        this.longitude = longitude;
        this.located = true;
        this.universe.trigger('user.located', { latitude, longitude });
    }
}
