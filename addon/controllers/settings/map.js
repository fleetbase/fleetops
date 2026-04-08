import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

const MAP_PROVIDER_OPTIONS = [
    { label: 'Leaflet (OpenStreetMap)', value: 'leaflet' },
    { label: 'Google Maps', value: 'google' },
];

const GOOGLE_MAP_TYPE_OPTIONS = [
    { label: 'Roadmap', value: 'roadmap' },
    { label: 'Satellite', value: 'satellite' },
    { label: 'Hybrid', value: 'hybrid' },
    { label: 'Terrain', value: 'terrain' },
];

export default class SettingsMapController extends Controller {
    @service fetch;
    @service notifications;
    @service intl;
    @service mapManager;

    /**
     * Available map provider options for the provider selector.
     *
     * @memberof SettingsMapController
     * @var {Array}
     */
    @tracked mapProviderOptions = MAP_PROVIDER_OPTIONS;

    /**
     * Available Google Maps map-type options.
     *
     * @memberof SettingsMapController
     * @var {Array}
     */
    @tracked googleMapTypeOptions = GOOGLE_MAP_TYPE_OPTIONS;

    /**
     * The currently-selected map provider key (e.g. 'leaflet' or 'google').
     *
     * @memberof SettingsMapController
     * @var {String}
     */
    @tracked mapProvider = 'leaflet';

    /**
     * The Google Maps API key entered by the user.
     * Stored separately from the settings blob so it is never leaked.
     *
     * @memberof SettingsMapController
     * @var {String}
     */
    @tracked googleMapsApiKey = '';

    /**
     * The selected Google Maps map type (roadmap / satellite / hybrid / terrain).
     *
     * @memberof SettingsMapController
     * @var {String}
     */
    @tracked googleMapsMapType = 'roadmap';

    /**
     * Whether to overlay the Google Maps traffic layer.
     *
     * @memberof SettingsMapController
     * @var {Boolean}
     */
    @tracked googleMapsTrafficLayer = false;

    /**
     * Whether to overlay the Google Maps transit layer.
     *
     * @memberof SettingsMapController
     * @var {Boolean}
     */
    @tracked googleMapsTransitLayer = false;

    /**
     * Whether settings have been loaded from the server.
     *
     * @memberof SettingsMapController
     * @var {Boolean}
     */
    @tracked settingsLoaded = false;

    constructor() {
        super(...arguments);
        this.getSettings.perform();
    }

    // ─── Computed getters ──────────────────────────────────────────────────────

    /**
     * True when the user has selected Google Maps as the provider.
     *
     * @memberof SettingsMapController
     * @return {Boolean}
     */
    get isGoogleMapsSelected() {
        return this.mapProvider === 'google';
    }

    /**
     * The currently-selected provider option object (for the Select component).
     *
     * @memberof SettingsMapController
     * @return {Object}
     */
    get selectedMapProvider() {
        return MAP_PROVIDER_OPTIONS.find((o) => o.value === this.mapProvider) ?? MAP_PROVIDER_OPTIONS[0];
    }

    /**
     * The currently-selected Google Maps type option object (for the Select component).
     *
     * @memberof SettingsMapController
     * @return {Object}
     */
    get selectedGoogleMapType() {
        return GOOGLE_MAP_TYPE_OPTIONS.find((o) => o.value === this.googleMapsMapType) ?? GOOGLE_MAP_TYPE_OPTIONS[0];
    }

    // ─── Actions ───────────────────────────────────────────────────────────────

    /**
     * Called when the user picks a different map provider from the Select.
     *
     * @param {Object} option  The selected option object { label, value }
     * @memberof SettingsMapController
     */
    @action selectMapProvider(option) {
        this.mapProvider = option?.value ?? 'leaflet';
    }

    /**
     * Called when the user picks a different Google Maps map type.
     *
     * @param {Object} option  The selected option object { label, value }
     * @memberof SettingsMapController
     */
    @action selectGoogleMapType(option) {
        this.googleMapsMapType = option?.value ?? 'roadmap';
    }

    /**
     * Called when the user types in the Google Maps API key field.
     *
     * @param {Event} event
     * @memberof SettingsMapController
     */
    @action onApiKeyChange(event) {
        this.googleMapsApiKey = event?.target?.value ?? '';
    }

    /**
     * Toggle the Google Maps traffic layer setting.
     *
     * @memberof SettingsMapController
     */
    @action toggleTrafficLayer() {
        this.googleMapsTrafficLayer = !this.googleMapsTrafficLayer;
    }

    /**
     * Toggle the Google Maps transit layer setting.
     *
     * @memberof SettingsMapController
     */
    @action toggleTransitLayer() {
        this.googleMapsTransitLayer = !this.googleMapsTransitLayer;
    }

    // ─── Tasks ─────────────────────────────────────────────────────────────────

    /**
     * Persist map settings to the server.
     *
     * @memberof SettingsMapController
     */
    @task *saveSettings() {
        const settings = {
            mapProvider: this.mapProvider,
            googleMapsMapType: this.googleMapsMapType,
            googleMapsTrafficLayer: this.googleMapsTrafficLayer,
            googleMapsTransitLayer: this.googleMapsTransitLayer,
        };

        // Only include the API key in the payload when the user has typed one,
        // so that an empty submission does not overwrite a previously saved key.
        if (this.googleMapsApiKey && this.googleMapsApiKey.trim().length > 0) {
            settings.googleMapsApiKey = this.googleMapsApiKey.trim();
        }

        try {
            yield this.fetch.post('fleet-ops/settings/map', { settings });

            // Apply the new provider to the live mapManager so the map
            // switches without requiring a full page reload.
            if (this.mapManager && typeof this.mapManager.setActiveProvider === 'function') {
                this.mapManager.setActiveProvider(this.mapProvider);
            }

            this.notifications.success(this.intl.t('map-settings.settings-saved'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Load map settings from the server.
     *
     * @memberof SettingsMapController
     */
    @task *getSettings() {
        try {
            const response = yield this.fetch.get('fleet-ops/settings/map');

            this.mapProvider = response?.mapProvider ?? 'leaflet';
            this.googleMapsMapType = response?.googleMapsMapType ?? 'roadmap';
            this.googleMapsTrafficLayer = response?.googleMapsTrafficLayer ?? false;
            this.googleMapsTransitLayer = response?.googleMapsTransitLayer ?? false;
            // The API key is intentionally NOT returned by the server for security.
            // We leave the field blank so the user can enter a new key if needed.
            this.googleMapsApiKey = '';
            this.settingsLoaded = true;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
