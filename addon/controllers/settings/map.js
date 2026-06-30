import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import MAP_PROVIDER_OPTIONS from '../../utils/map-provider-options';

export default class SettingsMapController extends Controller {
    @service fetch;
    @service notifications;
    @service intl;
    @service mapManager;
    @service mapSettings;

    /**
     * Available map provider options for the provider selector.
     *
     * @memberof SettingsMapController
     * @var {Array}
     */
    @tracked mapProviderOptions = MAP_PROVIDER_OPTIONS;
    @tracked googleMapsMapTypeOptions = [
        { label: 'Roadmap', value: 'roadmap' },
        { label: 'Satellite', value: 'satellite' },
        { label: 'Hybrid', value: 'hybrid' },
        { label: 'Terrain', value: 'terrain' },
    ];

    /**
     * The currently-selected map provider key (e.g. 'leaflet' or 'google').
     *
     * @memberof SettingsMapController
     * @var {String}
     */
    @tracked mapProvider = 'leaflet';
    @tracked googleMapsMapType = 'roadmap';
    @tracked showGoogleMapsTrafficLayer = false;
    @tracked showGoogleMapsTransitLayer = false;

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

    // ─── Actions ───────────────────────────────────────────────────────────────

    /**
     * Called when the user picks a different map provider from the Select.
     *
     * @param {Object|String} option  The selected option object or raw value.
     * @memberof SettingsMapController
     */
    @action selectMapProvider(option) {
        this.mapProvider = option?.value ?? option ?? 'leaflet';
    }

    @task *saveSettings() {
        const settings = {
            mapProvider: this.mapProvider,
            googleMapsMapType: this.googleMapsMapType,
            showGoogleMapsTrafficLayer: this.showGoogleMapsTrafficLayer,
            showGoogleMapsTransitLayer: this.showGoogleMapsTransitLayer,
        };

        try {
            const response = yield this.fetch.post('fleet-ops/settings/map', { settings });
            this.mapSettings.applySettings(response);

            if (this.mapManager && typeof this.mapManager.setActiveProvider === 'function') {
                this.mapManager.setActiveProvider(this.mapProvider);
            }

            if (this.mapProvider === 'google' && typeof this.mapManager?.applyViewSettingsFromSettings === 'function') {
                yield this.mapManager.applyViewSettingsFromSettings();
            }

            this.notifications.success(this.intl.t('settings.map.settings-saved'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *getSettings() {
        try {
            const response = yield this.mapSettings.load({ force: true });

            this.mapProvider = response?.mapProvider ?? 'leaflet';
            this.googleMapsMapType = response?.googleMapsMapType ?? 'roadmap';
            this.showGoogleMapsTrafficLayer = Boolean(response?.showGoogleMapsTrafficLayer);
            this.showGoogleMapsTransitLayer = Boolean(response?.showGoogleMapsTransitLayer);
            this.settingsLoaded = true;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
