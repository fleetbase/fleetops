import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { getLeafletTileUrl } from '../utils/leaflet-tile-url';

const DEFAULT_SETTINGS = {
    mapProvider: 'leaflet',
    leafletTileUrl: '',
    leafletDarkTileUrl: '',
    googleMapsApiKey: '',
    googleMapsMapId: '',
    googleMapsMapType: 'roadmap',
    showGoogleMapsTrafficLayer: false,
    showGoogleMapsTransitLayer: false,
};

export default class MapSettingsService extends Service {
    @service fetch;
    @tracked settings = { ...DEFAULT_SETTINGS };
    @tracked isLoaded = false;
    @tracked loadPromise = null;

    get mapProvider() {
        return this.settings.mapProvider ?? 'leaflet';
    }

    get googleMapsApiKey() {
        return this.settings.googleMapsApiKey ?? '';
    }

    get googleMapsMapId() {
        return this.settings.googleMapsMapId ?? '';
    }

    get googleMapsMapType() {
        return this.settings.googleMapsMapType ?? 'roadmap';
    }

    get showGoogleMapsTrafficLayer() {
        return Boolean(this.settings.showGoogleMapsTrafficLayer);
    }

    get showGoogleMapsTransitLayer() {
        return Boolean(this.settings.showGoogleMapsTransitLayer);
    }

    get isGoogleMaps() {
        return this.mapProvider === 'google';
    }

    get leafletTileUrl() {
        return this.getLeafletTileUrl('light');
    }

    get leafletDarkTileUrl() {
        return this.getLeafletTileUrl('dark');
    }

    /**
     * Resolve the Leaflet tile URL for a theme, preferring the company's
     * configured custom tile provider and falling back to the keyless default.
     *
     * @param {String} theme 'light' or 'dark'
     * @return {String}
     */
    getLeafletTileUrl(theme = 'light') {
        return getLeafletTileUrl(this.settings, theme);
    }

    async load({ force = false } = {}) {
        if (!force && this.isLoaded) {
            return this.settings;
        }

        if (!force && this.loadPromise) {
            return this.loadPromise;
        }

        this.loadPromise = this.fetch
            .get('fleet-ops/settings/map')
            .then((settings) => this.applySettings(settings))
            .catch(() => {
                this.settings = { ...DEFAULT_SETTINGS };
                this.isLoaded = true;
                return this.settings;
            })
            .finally(() => {
                this.loadPromise = null;
            });

        return this.loadPromise;
    }

    applySettings(settings = {}) {
        this.settings = {
            ...DEFAULT_SETTINGS,
            ...settings,
        };
        this.isLoaded = true;
        return this.settings;
    }

    setMapProvider(mapProvider = 'leaflet') {
        this.settings = {
            ...this.settings,
            mapProvider,
        };

        this.isLoaded = true;
        return this.settings;
    }
}
