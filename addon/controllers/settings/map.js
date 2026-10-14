import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import MAP_PROVIDER_OPTIONS from '../../utils/map-provider-options';
import { isLatitude, isLongitude, isZoom, toCoordinate } from '../../utils/map-default-view';

export default class SettingsMapController extends Controller {
    @service fetch;
    @service notifications;
    @service intl;
    @service mapManager;
    @service mapSettings;
    @service location;

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
    @tracked leafletTileUrl = '';
    @tracked leafletDarkTileUrl = '';
    @tracked googleMapsMapType = 'roadmap';
    @tracked showGoogleMapsTrafficLayer = false;
    @tracked showGoogleMapsTransitLayer = false;

    /**
     * The company's default live map view. Latitude and longitude are both
     * set or both null; zoom is optional.
     */
    @tracked defaultMapLatitude = null;
    @tracked defaultMapLongitude = null;
    @tracked defaultMapZoom = null;
    @tracked fitMapToVehicles = false;

    /**
     * Bumped to re-create the coordinates input, which only reads its value
     * when it is constructed (e.g. after clearing the default center).
     */
    @tracked defaultCenterInputKey = 0;

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
     * True when the user has selected Leaflet as the provider.
     *
     * @memberof SettingsMapController
     * @return {Boolean}
     */
    get isLeafletSelected() {
        return this.mapProvider === 'leaflet';
    }

    /**
     * The default center as a GeoJSON point for the coordinates input, or null.
     *
     * @memberof SettingsMapController
     * @return {Object|null}
     */
    get defaultMapCenterPoint() {
        // Not null: CoordinatesInput#isPoint reads `.type` on any object, and typeof null is "object".
        if (!this.hasDefaultMapCenter) {
            return undefined;
        }

        return { type: 'Point', coordinates: [this.defaultMapLongitude, this.defaultMapLatitude] };
    }

    get hasDefaultMapCenter() {
        return isLatitude(this.defaultMapLatitude) && isLongitude(this.defaultMapLongitude);
    }

    /**
     * The zoom to save: a whole number from 1 to 20, else null.
     *
     * @memberof SettingsMapController
     * @return {Number|null}
     */
    get normalizedDefaultMapZoom() {
        const zoom = toCoordinate(this.defaultMapZoom);
        return isZoom(zoom) ? zoom : null;
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

    /**
     * Receives coordinates typed into, or picked on the map of, the default
     * center input.
     *
     * @param {{latitude: *, longitude: *}} coordinates
     * @memberof SettingsMapController
     */
    @action setDefaultMapCenter({ latitude, longitude } = {}) {
        this.defaultMapLatitude = toCoordinate(latitude);
        this.defaultMapLongitude = toCoordinate(longitude);
    }

    @action clearDefaultMapCenter() {
        this.defaultMapLatitude = null;
        this.defaultMapLongitude = null;
        this.defaultCenterInputKey++;
    }

    @task *saveSettings() {
        const settings = {
            mapProvider: this.mapProvider,
            leafletTileUrl: (this.leafletTileUrl ?? '').trim(),
            leafletDarkTileUrl: (this.leafletDarkTileUrl ?? '').trim(),
            googleMapsMapType: this.googleMapsMapType,
            showGoogleMapsTrafficLayer: this.showGoogleMapsTrafficLayer,
            showGoogleMapsTransitLayer: this.showGoogleMapsTransitLayer,
            defaultMapLatitude: this.hasDefaultMapCenter ? this.defaultMapLatitude : null,
            defaultMapLongitude: this.hasDefaultMapCenter ? this.defaultMapLongitude : null,
            defaultMapZoom: this.normalizedDefaultMapZoom,
            fitMapToVehicles: this.fitMapToVehicles,
        };

        try {
            const response = yield this.fetch.post('fleet-ops/settings/map', { settings });
            this.mapSettings.applySettings(response);

            // Re-resolve where maps open so an open live map moves to the new default.
            this.location.relocate();

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
            this.leafletTileUrl = response?.leafletTileUrl ?? '';
            this.leafletDarkTileUrl = response?.leafletDarkTileUrl ?? '';
            this.googleMapsMapType = response?.googleMapsMapType ?? 'roadmap';
            this.showGoogleMapsTrafficLayer = Boolean(response?.showGoogleMapsTrafficLayer);
            this.showGoogleMapsTransitLayer = Boolean(response?.showGoogleMapsTransitLayer);
            this.setDefaultMapCenter({ latitude: response?.defaultMapLatitude, longitude: response?.defaultMapLongitude });
            this.defaultMapZoom = response?.defaultMapZoom ?? null;
            this.fitMapToVehicles = Boolean(response?.fitMapToVehicles);
            this.settingsLoaded = true;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
