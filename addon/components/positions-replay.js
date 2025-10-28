import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, set } from '@ember/object';
import { task } from 'ember-concurrency';
import { isArray } from '@ember/array';
import { guidFor } from '@ember/object/internals';
import { htmlSafe } from '@ember/template';
import { startOfWeek, endOfWeek, format } from 'date-fns';
import getModelName from '@fleetbase/ember-core/utils/get-model-name';

export default class PositionsReplayComponent extends Component {
    @service store;
    @service fetch;
    @service positionPlayback;
    @service notifications;
    @service location;

    /** Component ID */
    id = guidFor(this);

    /** Tracked properties - only what's NOT managed by service */
    @tracked positions = [];
    @tracked selectedOrder = null;
    @tracked dateFilter = [format(startOfWeek(new Date(), { weekStartsOn: 1 }), 'yyyy-MM-dd'), format(endOfWeek(new Date(), { weekStartsOn: 1 }), 'yyyy-MM-dd')];
    @tracked map = null;
    @tracked replaySpeed = '1';
    @tracked metrics = null;
    @tracked latitude = this.args.resource.latitude || this.location.getLatitude();
    @tracked longitude = this.args.resource.longitude || this.location.getLongitude();
    @tracked zoom = 14;
    @tracked tileUrl = 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png';

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

    get resource() {
        return this.args.resource;
    }

    get resourceName() {
        if (!this.resource) {
            return 'Unknown';
        }
        return this.resource.name || this.resource.display_name || this.resource.displayName || this.resource.public_id || 'Resource';
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

    get firstPosition() {
        return this.positions.length > 0 ? this.positions[0] : null;
    }

    get lastPosition() {
        return this.positions.length > 0 ? this.positions[this.positions.length - 1] : null;
    }

    get totalDistance() {
        return this.metrics?.total_distance ?? 0;
    }

    get totalDuration() {
        return this.metrics?.total_duration ?? 0;
    }

    get maxSpeed() {
        return this.metrics?.max_speed ?? 0;
    }

    get avgSpeed() {
        return this.metrics?.avg_speed ?? 0;
    }

    get speedingCount() {
        return this.metrics?.speeding_count ?? 0;
    }

    get dwellCount() {
        return this.metrics?.dwell_count ?? 0;
    }

    get accelerationCount() {
        return this.metrics?.acceleration_count ?? 0;
    }

    get formattedDuration() {
        const seconds = this.totalDuration;
        const days = Math.floor(seconds / 86400);
        const hours = Math.floor((seconds % 86400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;

        if (days > 0) {
            return `${days}d ${hours}h ${minutes}m ${secs}s`;
        } else if (hours > 0) {
            return `${hours}h ${minutes}m ${secs}s`;
        } else if (minutes > 0) {
            return `${minutes}m ${secs}s`;
        } else {
            return `${secs}s`;
        }
    }

    /** Constants */
    speedOptions = [
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

    /** Lifecycle */
    constructor() {
        super(...arguments);

        // Validate resource argument
        if (!this.args.resource) {
            console.warn('PositionsReplay: @resource argument is required');
        }

        this.loadPositions.perform();
    }

    willDestroy() {
        super.willDestroy?.();

        // Clean up replay tracker on component destroy
        this.positionPlayback.reset();
    }

    /** Actions */
    @action didLoadMap({ target: map }) {
        this.map = map;
        requestAnimationFrame(() => map.invalidateSize());

        const hasValidCoordinates = this.args.resource?.hasValidCoordinates || (Number.isFinite(this.args.resource?.latitude) && Number.isFinite(this.args.resource?.longitude));
        if (hasValidCoordinates) {
            const coordinates = [this.args.resource.latitude, this.args.resource.longitude];

            // Use flyTo with a zoom level of 16 for a smooth animation
            this.map.flyTo(coordinates, 16, {
                animate: true,
                duration: 0.8,
            });
        }
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
        if (this.map && position.latitude && position.longitude) {
            this.map.setView([position.latitude, position.longitude], this.zoom);
        }
    }

    @action onTrackingMarkerAdded(resource, { target: layer }) {
        this.#setResourceLayer(resource, layer);
    }

    /** Tasks */
    @task *loadPositions() {
        if (!this.args.resource) {
            this.notifications.warning('No resource provided for position query');
            return;
        }

        try {
            const params = {
                limit: 900,
                sort: 'created_at',
                subject_uuid: this.args.resource.id,
            };

            if (this.selectedOrder) {
                params.order_uuid = this.selectedOrder.id;
            }

            if (isArray(this.dateFilter) && this.dateFilter.length === 2) {
                params.created_at = this.dateFilter.join(',');
            }

            const positions = yield this.store.query('position', params);
            this.positions = isArray(positions) ? positions : [];

            if (this.positions?.length) {
                yield this.loadMetrics.perform();

                const bounds = positions
                    .filter(({ latitude, longitude }) => this.#isValidLatLng(latitude, longitude))
                    .map((pos) => pos.latLng)
                    .filter(Boolean);
                const lastFiveBounds = bounds.slice(-5);
                this.map.flyToBounds(lastFiveBounds, {
                    animate: true,
                    zoom: 16,
                });
            }

            // Reset replay state when positions change
            this.stopReplay();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *loadMetrics() {
        try {
            const positionIds = this.positions.map((p) => p.id);

            if (positionIds.length === 0) {
                return;
            }

            const response = yield this.fetch.post('positions/metrics', {
                position_ids: positionIds,
            });

            if (response && response.metrics) {
                this.metrics = response.metrics;
            }
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
        if (!this.args.resource) {
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
            map: this.map,
            callback: (data) => {
                if (data.type === 'complete') {
                    // Replay completed
                    this.notifications.success('Replay completed');
                }
            },
        });
    }

    #setResourceLayer(model, layer) {
        const type = getModelName(model);

        set(model, 'leafletLayer', layer);
        set(layer, 'record_id', model.id);
        set(layer, 'record_type', type);
    }

    #isValidLatLng(lat, lng) {
        return Number.isFinite(lat) && Number.isFinite(lng) && lat <= 90 && lat >= -90 && lng <= 180 && lng >= -180 && lat !== 0 && lng !== 0;
    }
}
