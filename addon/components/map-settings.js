/**
 * MapSettings component
 *
 * A settings panel that allows administrators to configure the map provider
 * (Leaflet or Google Maps) and related options at runtime.
 *
 * Settings are persisted via the Fleetbase settings API so that the choice
 * survives page reloads and is shared across all users in the organisation.
 *
 * Usage:
 *   <MapSettings />
 *
 * The component is designed to be embedded in the FleetOps admin settings
 * page alongside other settings panels (e.g. DriverOnboardSettings).
 */
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { debug } from '@ember/debug';

/** Available map provider options for the Select dropdown. */
const MAP_PROVIDER_OPTIONS = [
    { label: 'Leaflet (Default — OpenStreetMap)', value: 'leaflet' },
    { label: 'Google Maps', value: 'google' },
];

/** Available Google Maps map-type options. */
const GOOGLE_MAP_TYPE_OPTIONS = [
    { label: 'Roadmap', value: 'roadmap' },
    { label: 'Satellite', value: 'satellite' },
    { label: 'Hybrid', value: 'hybrid' },
    { label: 'Terrain', value: 'terrain' },
];

export default class MapSettingsComponent extends Component {
    @service fetch;
    @service notifications;
    @service intl;
    @service mapManager;
    @service universe;

    /** Available provider options for the Select component. */
    mapProviderOptions = MAP_PROVIDER_OPTIONS;

    /** Available Google Maps type options. */
    googleMapTypeOptions = GOOGLE_MAP_TYPE_OPTIONS;

    /** Whether the settings have been loaded from the API. */
    @tracked mapSettingsLoaded = false;

    /** Current settings object (mirrors the API response). */
    @tracked mapSettings = {
        mapProvider: 'leaflet',
        googleMapsApiKey: '',
        googleMapsMapType: 'roadmap',
        googleMapsTrafficLayer: false,
        googleMapsTransitLayer: false,
        leafletTileProvider: 'carto-light',
        leafletCustomTileUrl: '',
    };

    constructor() {
        super(...arguments);
        this.loadMapSettings.perform();
    }

    // ─── Data Loading ─────────────────────────────────────────────────────────

    @task *loadMapSettings() {
        try {
            const settings = yield this.fetch.get('fleet-ops/settings/map', {}, { normalizeToEmberData: false });
            if (settings) {
                this.mapSettings = { ...this.mapSettings, ...settings };
            }
            this.mapSettingsLoaded = true;
        } catch (err) {
            debug(`[MapSettings] Failed to load map settings: ${err.message}`);
            this.mapSettingsLoaded = true;
        }
    }

    // ─── Actions ──────────────────────────────────────────────────────────────

    /**
     * Handle map provider selection change.
     *
     * @param {{ value: string }} option
     */
    @action selectMapProvider(option) {
        this.mapSettings = { ...this.mapSettings, mapProvider: option.value };
    }

    /**
     * Handle Google Maps map-type selection change.
     *
     * @param {{ value: string }} option
     */
    @action selectGoogleMapType(option) {
        this.mapSettings = { ...this.mapSettings, googleMapsMapType: option.value };
    }

    /**
     * Toggle the Google Maps traffic layer.
     */
    @action toggleTrafficLayer() {
        this.mapSettings = { ...this.mapSettings, googleMapsTrafficLayer: !this.mapSettings.googleMapsTrafficLayer };
    }

    /**
     * Toggle the Google Maps transit layer.
     */
    @action toggleTransitLayer() {
        this.mapSettings = { ...this.mapSettings, googleMapsTransitLayer: !this.mapSettings.googleMapsTransitLayer };
    }

    /**
     * Update the Google Maps API key field.
     *
     * @param {Event} event
     */
    @action onApiKeyChange(event) {
        this.mapSettings = { ...this.mapSettings, googleMapsApiKey: event.target.value };
    }

    /**
     * Update the custom Leaflet tile URL field.
     *
     * @param {Event} event
     */
    @action onCustomTileUrlChange(event) {
        this.mapSettings = { ...this.mapSettings, leafletCustomTileUrl: event.target.value };
    }

    /**
     * Persist the current settings to the API and apply them to the live map.
     */
    @task *saveMapSettings() {
        try {
            yield this.fetch.post('fleet-ops/settings/map', { settings: this.mapSettings });

            // Apply the new provider immediately without requiring a page reload
            if (this.mapSettings.mapProvider !== this.mapManager.providerName) {
                this.mapManager.setActiveProvider(this.mapSettings.mapProvider);
                // Notify the universe so the live map component can re-render
                this.universe.trigger('fleet-ops:map-provider-changed', this.mapSettings.mapProvider);
            }

            this.notifications.success(this.intl.t('map-settings.settings-saved'));
        } catch (err) {
            debug(`[MapSettings] Failed to save map settings: ${err.message}`);
            this.notifications.serverError?.(err) ?? this.notifications.error(this.intl.t('map-settings.settings-save-failed'));
        }
    }

    // ─── Computed helpers ─────────────────────────────────────────────────────

    /** Whether the Google Maps provider is currently selected. */
    get isGoogleMapsSelected() {
        return this.mapSettings.mapProvider === 'google';
    }

    /** The currently-selected provider option object (for the Select component). */
    get selectedMapProvider() {
        return MAP_PROVIDER_OPTIONS.find((o) => o.value === this.mapSettings.mapProvider) ?? MAP_PROVIDER_OPTIONS[0];
    }

    /** The currently-selected Google Maps type option object. */
    get selectedGoogleMapType() {
        return GOOGLE_MAP_TYPE_OPTIONS.find((o) => o.value === this.mapSettings.googleMapsMapType) ?? GOOGLE_MAP_TYPE_OPTIONS[0];
    }
}
