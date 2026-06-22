import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';

const DEFAULT_SETTINGS = {
    mapProvider: 'leaflet',
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
