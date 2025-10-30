import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { isArray } from '@ember/array';
import { htmlSafe } from '@ember/template';
import { startOfWeek, endOfWeek, format } from 'date-fns';
import getModelName from '@fleetbase/ember-core/utils/get-model-name';

const L = window.leaflet || window.L;

export default class MapDrawerPositionListingComponent extends Component {
    @service leafletMapManager;
    @service store;
    @service fetch;
    @service positionPlayback;
    @service hostRouter;
    @service notifications;
    @service intl;

    /** Tracked properties - only what's NOT managed by service */
    @tracked positions = [];
    @tracked resource = null;
    @tracked selectedOrder = null;
    @tracked dateFilter = [format(startOfWeek(new Date(), { weekStartsOn: 1 }), 'yyyy-MM-dd'), format(endOfWeek(new Date(), { weekStartsOn: 1 }), 'yyyy-MM-dd')];
    @tracked replaySpeed = '1';
    @tracked positionsLayer = null;

    /** Computed properties - read state from service */
    get isReplaying() {
        return this.positionPlayback.isPlaying;
    }

    get isPaused() {
        return this.positionPlayback.isPaused;
    }

    get currentReplayIndex() {
        return this.positionPlayback.currentIndex;
    }

    get trackables() {
        const vehicles = this.leafletMapManager._livemap?.vehicles ?? [];
        const drivers = this.leafletMapManager._livemap?.drivers ?? [];

        return [...vehicles, ...drivers];
    }

    get replayProgressWidth() {
        return htmlSafe(`width: ${this.replayProgress}%;`);
    }

    get orderFilters() {
        const params = {};

        if (this.resourceType === 'vehicle') {
            params.vehicle_assigned_uuid = this.resource?.id;
        }

        if (this.resourceType === 'driver') {
            params.driver_assigned_uuid = this.resource?.id;
        }

        return params;
    }

    get resourceType() {
        if (!this.resource) {
            return 'resource';
        }
        return getModelName(this.resource) || 'resource';
    }

    get hasPositions() {
        return this.positions.length > 0;
    }

    get totalPositions() {
        return this.positions.length;
    }

    get replayProgress() {
        if (this.totalPositions === 0) {
            return 0;
        }
        return Math.round((this.currentReplayIndex / this.totalPositions) * 100);
    }

    get speedOptions() {
        return [
            { label: '0.5x', value: '0.5' },
            { label: '1x', value: '1' },
            { label: '2x', value: '2' },
            { label: '5x', value: '5' },
            { label: '10x', value: '10' },
            { label: '20x', value: '20' },
            { label: '30x', value: '30' },
            { label: '40x', value: '40' },
            { label: '50x', value: '50' },
            { label: '100x', value: '100' },
            { label: '80x', value: '80' },
            { label: '120x', value: '120' },
            { label: '160x', value: '160' },
            { label: '180x', value: '180' },
            { label: '200x', value: '200' },
            { label: '250x', value: '250' },
            { label: '280x', value: '280' },
            { label: '300x', value: '300' },
            { label: '350x', value: '350' },
            { label: '400x', value: '400' },
            { label: '500x', value: '500' },
            { label: '600x', value: '600' },
            { label: '1000x', value: '1000' },
        ];
    }

    /** columns */
    get columns() {
        return [
            {
                label: '#',
                valuePath: 'index',
                width: '80px',
                cellComponent: 'table/cell/anchor',
                onClick: this.onPositionClicked,
            },
            {
                label: 'Timestamp',
                valuePath: 'timestamp',
                cellComponent: 'table/cell/anchor',
                onClick: this.onPositionClicked,
            },
            {
                label: 'Latitude',
                valuePath: 'latitude',
                cellComponent: 'table/cell/anchor',
                onClick: this.onPositionClicked,
            },
            {
                label: 'Longitude',
                valuePath: 'longitude',
                cellComponent: 'table/cell/anchor',
                onClick: this.onPositionClicked,
            },
            {
                label: 'Speed (km/h)',
                valuePath: 'speedKmh',
            },
            {
                label: 'Heading',
                valuePath: 'heading',
            },
            {
                label: 'Altitude (m)',
                valuePath: 'altitude',
            },
        ];
    }

    constructor() {
        super(...arguments);
        this.loadPositions.perform();
    }

    willDestroy() {
        super.willDestroy?.();

        // Clean up replay tracker
        this.positionPlayback.reset();

        // Remove our layer group from the map
        this.#clearPositionsLayer(true);
        this.positionsLayer = null;
    }

    @action onResourceSelected(resource) {
        this.resource = resource;
        this.focusResource(resource);
        this.loadPositions.perform();
    }

    @action onOrderSelected(order) {
        this.selectedOrder = order;
        this.loadPositions.perform();
    }

    @action onDateRangeChanged({ formattedDate }) {
        if (isArray(formattedDate) && formattedDate.length === 2) {
            this.dateFilter = formattedDate;
            this.loadPositions.perform();
        }
    }

    @action onSpeedChanged(speed) {
        this.replaySpeed = speed;

        // Update replay speed in real-time if currently playing
        if (this.isReplaying) {
            this.positionPlayback.setSpeed(parseFloat(speed));
        }
    }

    @action startReplay() {
        if (this.positions.length === 0) {
            this.notifications.warning('No positions to replay');
            return;
        }

        if (this.isReplaying && !this.isPaused) {
            this.notifications.info('Replay is already running');
            return;
        }

        // If paused, resume
        if (this.isPaused) {
            this.positionPlayback.play();
            return;
        }

        // Start new replay
        this.#initializeReplay();
        this.positionPlayback.play();
    }

    @action pauseReplay() {
        if (!this.isReplaying) {
            return;
        }

        this.positionPlayback.pause();
    }

    @action stopReplay() {
        this.positionPlayback.stop();
    }

    @action stepForward() {
        if (this.isReplaying) {
            this.pauseReplay();
        }
        this.positionPlayback.stepForward(1);
    }

    @action stepBackward() {
        if (this.isReplaying) {
            this.pauseReplay();
        }
        this.positionPlayback.stepBackward(1);
    }

    @action clearFilters() {
        this.selectedOrder = null;
        this.dateFilter = null;
        this.loadPositions.perform();
    }

    @action onPositionClicked(position) {
        if (this.leafletMapManager.map && position.latitude && position.longitude) {
            this.leafletMapManager.map.setView([position.latitude, position.longitude], 16);
        }
    }

    @action focusResource(resource) {
        const hasValidCoordinates = resource?.hasValidCoordinates || (Number.isFinite(resource?.latitude) && Number.isFinite(resource?.longitude));
        if (hasValidCoordinates) {
            const coordinates = [resource.latitude, resource.longitude];

            // Use flyTo with a zoom level of 18 for a smooth animation
            this.leafletMapManager.map.flyTo(coordinates, 18, {
                animate: true,
                duration: 0.8,
            });
        }
    }

    @task *loadPositions() {
        if (!this.resource) return;

        try {
            const params = {
                limit: 900,
                sort: 'created_at',
                subject_uuid: this.resource.id,
            };

            if (this.selectedOrder) {
                params.order_uuid = this.selectedOrder.id;
            }

            if (isArray(this.dateFilter) && this.dateFilter.length === 2) {
                params.created_at = this.dateFilter.join(',');
            }

            const positions = yield this.store.query('position', params);
            this.positions = isArray(positions)
                ? positions.map((pos, index) => {
                      pos.set('index', index + 1);
                      return pos;
                  })
                : [];

            this.#renderPositionsOnMap({ fitLast: 5 });

            // Reset replay state when positions change
            this.stopReplay();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Initialize replay tracker with current positions and settings
     * This replaces the socket-based backend replay approach
     *
     * @private
     */
    #initializeReplay() {
        if (!this.resource) {
            this.notifications.warning('No resource provided for replay');
            return;
        }

        if (this.positions.length === 0) {
            this.notifications.warning('No positions to replay');
            return;
        }

        // Initialize replay tracker with positions
        this.positionPlayback.initialize({
            subject: this.resource,
            positions: this.positions,
            speed: parseFloat(this.replaySpeed),
            map: this.leafletMapManager.map,
            callback: (data) => {
                if (data.type === 'complete') {
                    // Replay completed
                    this.notifications.success('Replay completed');
                }
            },
        });
    }

    /** Position Rendering */
    #ensurePositionsLayer() {
        if (!this.leafletMapManager.map) return null;

        if (!this.positionsLayer) {
            this.positionsLayer = L.layerGroup();
            this.positionsLayer.addTo(this.leafletMapManager.map);
        }
        return this.positionsLayer;
    }

    #clearPositionsLayer(removeFromMap = false) {
        if (this.positionsLayer) {
            this.positionsLayer.clearLayers();
            if (removeFromMap && this.leafletMapManager.map) {
                this.positionsLayer.removeFrom(this.leafletMapManager.map);
            }
        }
    }

    #addPositionMarker(pos, index) {
        const lat = parseFloat(pos.latitude);
        const lng = parseFloat(pos.longitude);
        if (!this.#isValidLatLng(lat, lng)) return;

        const marker = L.circleMarker([lat, lng], {
            radius: 3,
            color: '#3b82f6',
            fillColor: '#3b82f6',
            fillOpacity: 0.6,
        });

        // Popup content (mirrors your template)
        const html = htmlSafe(`<div class="text-xs">
        <div><strong>Position ${index + 1}</strong></div>
        <div>Time: ${pos.timestamp ?? ''}</div>
        <div>Speed: ${pos.speedKmh ?? 'N/A'} km/h</div>
        <div>Heading: ${pos.heading ?? 'N/A'}°</div>
        <div>Altitude: ${pos.altitude ?? 'N/A'} m</div>
        </div>`);

        marker.bindPopup(html);

        // Click handler -> reuse your action
        marker.on('click', () => this.onPositionClicked(pos));

        marker.addTo(this.positionsLayer);
    }

    #isValidLatLng(lat, lng) {
        return Number.isFinite(lat) && Number.isFinite(lng) && lat <= 90 && lat >= -90 && lng <= 180 && lng >= -180 && lat !== 0 && lng !== 0;
    }

    #renderPositionsOnMap({ fitLast = 0, minZoom = 15, maxZoom = 18 } = {}) {
        if (!this.leafletMapManager.map || !this.positions?.length) {
            this.#clearPositionsLayer(false);
            return;
        }

        this.#ensurePositionsLayer();
        this.positionsLayer.clearLayers();

        const latlngs = [];
        this.positions.forEach((pos, i) => {
            const lat = parseFloat(pos.latitude);
            const lng = parseFloat(pos.longitude);
            if (this.#isValidLatLng(lat, lng)) {
                this.#addPositionMarker(pos, i);
                latlngs.push([lat, lng]);
            }
        });

        if (!latlngs.length) return;

        // choose subset (e.g., last 5 points) to bias the view local
        const slice = fitLast > 0 ? latlngs.slice(-fitLast) : latlngs;

        // Clamp zoom to neighborhood-level
        this.#fitNeighborhood(slice, { zoom: 16, minZoom, maxZoom, padding: [24, 24], animate: true });
    }

    #fitNeighborhood(latlngs, { zoom = null, minZoom = 15, maxZoom = 18, padding = [16, 16], animate = true } = {}) {
        if (!this.leafletMapManager.map || !latlngs?.length) return;

        const map = this.leafletMapManager.map;

        if (latlngs.length === 1) {
            // Single point → center on it at minZoom
            map.setView(latlngs[0], minZoom, { animate });
            return;
        }

        const bounds = L.latLngBounds(latlngs);
        // Compute the zoom that would fit the bounds, then clamp it
        // Leaflet getBoundsZoom may be (bounds, inside, padding) depending on version
        let targetZoom = zoom;
        if (!zoom) {
            try {
                // Try newer signature
                targetZoom = map.getBoundsZoom(bounds, true, padding);
            } catch {
                // Fallback without padding param
                targetZoom = map.getBoundsZoom(bounds, true);
            }
            targetZoom = Math.max(minZoom, Math.min(maxZoom, targetZoom));
        }

        // Center on bounds center with clamped zoom
        const center = bounds.getCenter();
        map.setView(center, targetZoom, { animate });
    }
}
