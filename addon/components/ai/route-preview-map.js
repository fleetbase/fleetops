import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import { isArray } from '@ember/array';
import { debug } from '@ember/debug';
import { colorForId, routeColorForStatus, routeStyleForStatus, waypointIconHtml } from '../../utils/route-colors';
import { buildRoutePointMarkerPresentation, buildRoutePointsFromPayload } from '../../utils/route-visualization';
import ensureLeafletPluginsReady, { hasLeafletPluginsReady } from '../../utils/leaflet-plugin-loader';

const PREVIEW_MAX_ZOOM_TWO_POINTS = 13;
const PREVIEW_MAX_ZOOM_MULTI_POINTS = 12;
const GOOGLE_MAP_STYLES = [
    {
        featureType: 'all',
        elementType: 'labels.icon',
        stylers: [{ visibility: 'off' }],
    },
    {
        featureType: 'poi',
        elementType: 'all',
        stylers: [{ visibility: 'off' }],
    },
    {
        featureType: 'transit',
        elementType: 'all',
        stylers: [{ visibility: 'off' }],
    },
    {
        featureType: 'administrative.land_parcel',
        elementType: 'all',
        stylers: [{ visibility: 'off' }],
    },
    {
        featureType: 'road',
        elementType: 'labels.icon',
        stylers: [{ visibility: 'off' }],
    },
    {
        featureType: 'landscape.man_made',
        elementType: 'labels',
        stylers: [{ visibility: 'off' }],
    },
];

export default class AiRoutePreviewMapComponent extends Component {
    @service mapSettings;
    @service routeEngine;

    @tracked route = null;
    @tracked error = null;
    @tracked isLoading = false;
    @tracked leafletPluginsReady = hasLeafletPluginsReady();
    @tracked googleMap = null;

    leafletMap = null;
    googleMarkers = [];
    googlePolylines = [];
    googleTrafficLayer = null;
    googleTransitLayer = null;
    computeToken = 0;
    googleApiLoadPromise = null;

    willDestroy() {
        super.willDestroy(...arguments);
        this.clearGoogleRoute();
        this.googleTrafficLayer?.setMap(null);
        this.googleTransitLayer?.setMap(null);
        this.googleMap = null;
        this.leafletMap = null;
    }

    get payload() {
        return this.args.payload ?? {};
    }

    get routePoints() {
        return buildRoutePointsFromPayload(this.payload);
    }

    get coordinates() {
        return this.routePoints.map(({ place }) => [Number(place.latitude), Number(place.longitude)]).filter(([lat, lng]) => Number.isFinite(lat) && Number.isFinite(lng));
    }

    get canShowMap() {
        return this.coordinates.length >= 2;
    }

    get routeSignature() {
        return `${this.provider}:${this.displayRouteEngine}:${this.coordinates.map((coordinate) => coordinate.join(',')).join('|')}`;
    }

    get provider() {
        return this.mapSettings.mapProvider ?? 'leaflet';
    }

    get shouldUseGoogleMaps() {
        return this.provider === 'google';
    }

    get displayRouteEngine() {
        const configuredEngine = this.routeEngine?.getDisplayEngine?.('osrm') ?? 'osrm';

        return this.routeEngine?.get?.(configuredEngine) ? configuredEngine : 'osrm';
    }

    get routeErrorMessage() {
        if (!this.error || this.isLoading || this.hasRouteLine) {
            return null;
        }

        return 'Unable to calculate route';
    }

    get center() {
        const [lat, lng] = this.coordinates[0] ?? [1.3521, 103.8198];

        return { lat, lng };
    }

    get routeColor() {
        return colorForId(this.args.orderId ?? 'ai-order-preview');
    }

    get routeStatus() {
        return this.args.status ?? 'created';
    }

    get statusColor() {
        return routeColorForStatus(this.routeStatus);
    }

    get routeStyles() {
        return routeStyleForStatus(this.routeStatus, this.statusColor);
    }

    get routeCoordinates() {
        return this.route?.coordinates?.length ? this.route.coordinates : this.coordinates;
    }

    get routeLineCoordinates() {
        return this.route?.coordinates?.length ? this.route.coordinates : [];
    }

    get hasRouteLine() {
        return this.routeLineCoordinates.length > 1;
    }

    get markerStops() {
        return this.routePoints
            .map((routePoint, index) => {
                const location = [Number(routePoint.place.latitude), Number(routePoint.place.longitude)];
                const presentation = buildRoutePointMarkerPresentation(routePoint, this.routeColor);

                return {
                    ...presentation,
                    index,
                    location,
                    title: presentation.title,
                    address: this.addressLabel(routePoint.place),
                };
            })
            .filter((stop) => stop.location.every((coordinate) => Number.isFinite(coordinate)));
    }

    get tileUrl() {
        const theme = document.body?.dataset?.theme;

        if (theme === 'dark') {
            return 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png';
        }

        return 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png';
    }

    get emptyText() {
        if (this.error) {
            return 'Unable to preview the route right now.';
        }

        return 'Select pickup and dropoff with coordinates to preview the route.';
    }

    addressLabel(place) {
        return [place?.address, place?.street1, place?.name, place?.city, place?.postal_code, place?.country]
            .map((value) => (value === null || value === undefined ? null : String(value).trim()))
            .filter((value) => value && !['undefined', 'null'].includes(value.toLowerCase()))
            .slice(0, 3)
            .join(' - ');
    }

    waypointIconHtml(label, color) {
        return waypointIconHtml(label, color);
    }

    @action async setupPreview() {
        await this.mapSettings.load();

        if (!this.shouldUseGoogleMaps && !this.leafletPluginsReady) {
            await this.prepareLeafletPlugins();
        }

        this.syncPreview();
    }

    @action async prepareLeafletPlugins() {
        if (this.leafletPluginsReady) {
            return;
        }

        await ensureLeafletPluginsReady();
        this.leafletPluginsReady = true;
    }

    @action didLoadLeafletMap({ target: map }) {
        this.leafletMap = map;
        this.syncPreview();
    }

    @action async setupGoogleMap(element) {
        await this.mapSettings.load();
        await this.loadGoogleMapsApi();

        const googleMaps = window.google;
        const { Map } = await googleMaps.maps.importLibrary('maps');
        const mapOptions = {
            center: { lat: this.center.lat, lng: this.center.lng },
            zoom: 12,
            mapTypeId: this.mapSettings.googleMapsMapType ?? googleMaps.maps.MapTypeId.ROADMAP,
            disableDefaultUI: true,
            gestureHandling: 'greedy',
            clickableIcons: false,
            styles: this.googleMapStyles,
        };

        if (this.mapSettings.googleMapsMapId) {
            mapOptions.mapId = this.mapSettings.googleMapsMapId;
        }

        this.googleMap = new Map(element, mapOptions);
        this.applyGoogleViewSettings();
        this.syncPreview();
    }

    @action syncPreview() {
        if (this.shouldUseGoogleMaps) {
            this.applyGoogleViewSettings();
        }

        this.computeAndDrawRoute();
    }

    async computeAndDrawRoute() {
        const token = ++this.computeToken;
        this.error = null;

        if (!this.canShowMap) {
            this.route = null;
            this.clearGoogleRoute();
            return;
        }

        this.isLoading = true;

        try {
            const route = await this.computeRoute();

            if (token !== this.computeToken) {
                return;
            }

            this.route = route;
            this.error = null;
            this.fitLeafletMap();
            this.drawGoogleRoute().catch((error) => this.logRenderError(error));
        } catch (error) {
            if (token !== this.computeToken) {
                return;
            }

            this.error = error;
            this.route = null;
            this.logRouteError(error);
            this.drawGoogleRoute().catch((renderError) => this.logRenderError(renderError));
        } finally {
            if (token === this.computeToken) {
                this.isLoading = false;
            }
        }
    }

    async computeRoute() {
        const engine = this.displayRouteEngine;
        const options = {
            payload: this.payload,
            fitOptions: this.fitOptions,
        };

        if (!this.routeEngine?.compute) {
            throw new Error('No route engine is available for the AI order preview.');
        }

        try {
            return await this.routeEngine.compute(engine, this.coordinates, options);
        } catch (error) {
            if (engine !== 'osrm') {
                return this.routeEngine.compute('osrm', this.coordinates, options);
            }

            throw error;
        }
    }

    get fitOptions() {
        return {
            padding: [18, 18],
            maxZoom: this.coordinates.length === 2 ? PREVIEW_MAX_ZOOM_TWO_POINTS : PREVIEW_MAX_ZOOM_MULTI_POINTS,
        };
    }

    get googleMapStyles() {
        if (this.mapSettings.showGoogleMapsTransitLayer) {
            return GOOGLE_MAP_STYLES.filter((style) => style.featureType !== 'transit');
        }

        return GOOGLE_MAP_STYLES;
    }

    fitLeafletMap() {
        if (!this.leafletMap || !this.routeCoordinates.length) {
            return;
        }

        const bounds = this.route?.bounds ?? this.routeCoordinates;

        if (isArray(bounds) && bounds.length > 1) {
            this.leafletMap.fitBounds(bounds, this.fitOptions);
        }
    }

    async drawGoogleRoute() {
        if (!this.googleMap || !this.shouldUseGoogleMaps) {
            return;
        }

        await this.loadGoogleMapsApi();
        this.clearGoogleRoute();

        const googleMaps = window.google;
        const path = this.routeLineCoordinates.map(([lat, lng]) => ({ lat, lng }));

        if (path.length > 1) {
            this.routeStyles.forEach((style) => {
                const polyline = new googleMaps.maps.Polyline({
                    path,
                    map: this.googleMap,
                    strokeColor: style.color,
                    strokeWeight: style.weight,
                    strokeOpacity: style.opacity,
                });

                this.googlePolylines.push(polyline);
            });
        }

        const { AdvancedMarkerElement } = await googleMaps.maps.importLibrary('marker');
        this.markerStops.forEach((stop) => {
            const position = { lat: stop.location[0], lng: stop.location[1] };
            const content = document.createElement('div');
            content.className = 'fleetops-map-marker';
            content.innerHTML = waypointIconHtml(stop.waypointLabel, stop.waypointColor);

            let marker;
            if (this.mapSettings.googleMapsMapId) {
                marker = new AdvancedMarkerElement({
                    map: this.googleMap,
                    position,
                    title: stop.title,
                    content,
                    zIndex: stop.zIndexOffset,
                });
            } else {
                marker = new googleMaps.maps.Marker({
                    map: this.googleMap,
                    position,
                    title: stop.title,
                    zIndex: stop.zIndexOffset,
                    icon: this.googleWaypointIcon(stop.waypointLabel, stop.waypointColor),
                });
            }

            this.googleMarkers.push(marker);
        });

        this.fitGoogleMap();
    }

    fitGoogleMap() {
        const googleMaps = window.google;
        if (!this.googleMap || !googleMaps?.maps || !this.routeCoordinates.length) {
            return;
        }

        const bounds = new googleMaps.maps.LatLngBounds();
        this.routeCoordinates.forEach(([lat, lng]) => bounds.extend({ lat, lng }));
        this.googleMap.fitBounds(bounds, 18);

        googleMaps.maps.event.addListenerOnce(this.googleMap, 'idle', () => {
            if (this.googleMap.getZoom() > (this.coordinates.length === 2 ? PREVIEW_MAX_ZOOM_TWO_POINTS : PREVIEW_MAX_ZOOM_MULTI_POINTS)) {
                this.googleMap.setZoom(this.coordinates.length === 2 ? PREVIEW_MAX_ZOOM_TWO_POINTS : PREVIEW_MAX_ZOOM_MULTI_POINTS);
            }
        });
    }

    clearGoogleRoute() {
        this.googleMarkers.forEach((marker) => {
            if ('map' in marker) {
                marker.map = null;
            } else {
                marker.setMap?.(null);
            }
        });
        this.googlePolylines.forEach((polyline) => polyline.setMap(null));
        this.googleMarkers = [];
        this.googlePolylines = [];
    }

    applyGoogleViewSettings() {
        if (!this.googleMap || !window.google?.maps) {
            return;
        }

        const googleMaps = window.google;
        this.googleTrafficLayer?.setMap(null);
        this.googleTransitLayer?.setMap(null);
        this.googleTrafficLayer = null;
        this.googleTransitLayer = null;
        this.googleMap.setOptions({
            mapTypeId: this.mapSettings.googleMapsMapType ?? googleMaps.maps.MapTypeId.ROADMAP,
            clickableIcons: false,
            styles: this.googleMapStyles,
        });

        if (this.mapSettings.showGoogleMapsTrafficLayer) {
            this.googleTrafficLayer = new googleMaps.maps.TrafficLayer();
            this.googleTrafficLayer.setMap(this.googleMap);
        }

        if (this.mapSettings.showGoogleMapsTransitLayer) {
            this.googleTransitLayer = new googleMaps.maps.TransitLayer();
            this.googleTransitLayer.setMap(this.googleMap);
        }
    }

    logRouteError(error) {
        debug(`[Fleet-Ops AI Route Preview] Unable to calculate route with ${this.displayRouteEngine}. coordinates=${this.coordinates.length}. error=${error?.message ?? error}`);
    }

    logRenderError(error) {
        debug(`[Fleet-Ops AI Route Preview] Route calculated but map rendering failed. error=${error?.message ?? error}`);
    }

    googleWaypointIcon(label, color) {
        const svg = `
            <svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 34 34">
                <circle cx="17" cy="17" r="15" fill="${color}" stroke="white" stroke-width="2.5" />
                <text x="17" y="21" text-anchor="middle" font-family="Inter, Arial, sans-serif" font-size="13" font-weight="700" fill="white">${label}</text>
            </svg>
        `;

        return {
            url: `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`,
            scaledSize: new window.google.maps.Size(34, 34),
            anchor: new window.google.maps.Point(17, 17),
        };
    }

    async loadGoogleMapsApi() {
        if (window.google?.maps?.importLibrary) {
            return true;
        }

        if (this.googleApiLoadPromise) {
            return this.googleApiLoadPromise;
        }

        const apiKey = this.mapSettings.googleMapsApiKey;
        this.googleApiLoadPromise = new Promise((resolve, reject) => {
            const callbackName = `__fleetops_ai_preview_gmaps_${Date.now().toString(36)}`;
            window[callbackName] = () => {
                delete window[callbackName];
                resolve(true);
            };

            const script = document.createElement('script');
            script.async = true;
            script.defer = true;
            script.onerror = () => {
                delete window[callbackName];
                reject(new Error('Failed to load Google Maps API for AI route preview.'));
            };
            script.src = `https://maps.googleapis.com/maps/api/js?key=${apiKey}&libraries=geometry,marker,routes&callback=${callbackName}&loading=async`;
            document.head.appendChild(script);
        });

        return this.googleApiLoadPromise;
    }
}
