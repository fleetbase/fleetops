import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { isArray } from '@ember/array';
import { htmlSafe } from '@ember/template';
import { startOfWeek, endOfWeek, format } from 'date-fns';
import getModelName from '@fleetbase/ember-core/utils/get-model-name';

export default class MapDrawerPositionListingComponent extends Component {
    @service leafletMapManager;
    @service store;
    @service fetch;
    @service movementTracker;
    @service hostRouter;
    @service notifications;

    @service intl;
    @tracked positions = [];
    @tracked resource = null;
    @tracked selectedOrder = null;
    @tracked dateFilter = [format(startOfWeek(new Date(), { weekStartsOn: 1 }), 'yyyy-MM-dd'), format(endOfWeek(new Date(), { weekStartsOn: 1 }), 'yyyy-MM-dd')];
    @tracked isReplaying = false;
    @tracked replaySpeed = '1';
    @tracked currentReplayIndex = 0;
    @tracked channelId = null;

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
                width: '55px',
            },
            {
                label: 'Timestamp',
                valuePath: 'timestamp',
            },
            {
                label: 'Latitude',
                valuePath: 'latitude',
            },
            {
                label: 'Longitude',
                valuePath: 'longitude',
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

    @action onResourceSelected(resource) {
        this.resource = resource;
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
    }

    @action startReplay() {
        if (this.positions.length === 0) {
            this.notifications.warning('No positions to replay');
            return;
        }
        this.replayPositions.perform();
    }

    @action stopReplay() {
        this.isReplaying = false;
        this.currentReplayIndex = 0;
    }

    @action clearFilters() {
        this.selectedOrder = null;
        this.dateFilter = null;
        this.loadPositions.perform();
    }

    @action onPositionClicked(position) {
        if (this.leafletMapManager.map && position.latitude && position.longitude) {
            this.leafletMapManager.map.setView([position.latitude, position.longitude], this.zoom);
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

            if (this.positions?.length) {
                const bounds = positions.map((pos) => pos.latLng).filter(Boolean);
                const lastFiveBounds = bounds.slice(-5);
                this.leafletMapManager.map.flyToBounds(lastFiveBounds, {
                    animate: true,
                    zoom: 16,
                });
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *replayPositions() {
        if (!this.resource) {
            this.notifications.warning('No resource provided for replay');
            return;
        }

        try {
            this.isReplaying = true;
            this.currentReplayIndex = 0;

            const positionIds = this.positions.map((p) => p.id);

            if (positionIds.length === 0) {
                this.notifications.warning('No positions to replay');
                this.isReplaying = false;
                return;
            }

            // Generate unique channel ID for this replay session
            this.channelId = `position.replay.${this.id}.${Date.now()}`;

            // Start tracking on custom channel
            yield this.movementTracker.track(this.resource, {
                channelId: this.channelId,
                callback: (output) => {
                    const {
                        data: { additionalData },
                    } = output;

                    const leafletLayer = this.resource.leafletLayer;
                    if (leafletLayer) {
                        const latlng = leafletLayer._slideToLatLng ?? leafletLayer.getLatLng();
                        this.leafletMapManager.map.panTo(latlng, { animate: true });
                    }

                    if (additionalData && Number.isFinite(additionalData.index)) {
                        this.currentReplayIndex = additionalData.index + 1;
                        if (this.currentReplayIndex === this.totalPositions) {
                            this.isReplaying = false;
                        }
                    }
                },
            });

            // Trigger backend replay
            const response = yield this.fetch.post('positions/replay', {
                position_ids: positionIds,
                channel_id: this.channelId,
                speed: parseFloat(this.replaySpeed),
                subject_uuid: this.resource.id,
            });

            if (response && response.status === 'ok') {
                this.notifications.success('Replay started successfully');
            }
        } catch (error) {
            this.notifications.serverError(error);
            this.isReplaying = false;
        }
    }
}
