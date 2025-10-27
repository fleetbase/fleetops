import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { getOwner } from '@ember/application';
import { task, timeout } from 'ember-concurrency';
import { debug } from '@ember/debug';
import getModelName from '@fleetbase/ember-core/utils/get-model-name';
import LeafletTrackingMarkerComponent from '../components/leaflet-tracking-marker';

export class EventBuffer {
    @tracked events = [];
    @tracked waitTime = 1000 * 3;
    @tracked callback;
    @tracked intervalId;
    @tracked model;

    constructor(model, { callback = null, waitTime = 1000 * 3 }) {
        this.model = model;
        this.callback = callback;
        this.waitTime = waitTime;
    }

    start() {
        this.intervalId = setInterval(() => {
            const bufferReady = this.process.isIdle && this.events.length > 0;
            if (bufferReady) {
                this.process.perform();
            }
        }, this.waitTime);
    }

    stop() {
        clearInterval(this.intervalId);
    }

    clear() {
        this.events = [];
    }

    add(event) {
        this.events = [...this.events, event];
    }

    removeByIndex(index) {
        this.events = this.events.filter((_, i) => i !== index);
    }

    remove(event) {
        this.events = this.events.filter((e) => e !== event);
    }

    @task *process() {
        debug('Processing movement tracker event buffer.');

        // Take a snapshot of events to process and clear buffer immediately
        // This prevents losing events that arrive during processing
        const eventsToProcess = [...this.events];
        this.events = []; // Clear immediately to accept new events

        // Sort events by created_at
        eventsToProcess.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
        debug(`[MovementTracker EventBuffer processing ${eventsToProcess.length} events]`);

        // Process sorted events
        for (const output of eventsToProcess) {
            const { event, data } = output;

            // get movingObject marker
            const marker = this.model.leafletLayer || this.model._layer || this.model._marker;
            if (!marker || !marker._map) {
                debug('No marker or marker not on map yet');
                continue;
            }

            // log incoming event
            debug(`${event} - ${data.id} ${data.additionalData?.index ? '#' + data.additionalData?.index : ''} (${output.created_at}) [ ${data.location.coordinates.join(' ')} ]`);

            // GeoJSON -> Leaflet [lat, lng]
            const [lng, lat] = data.location.coordinates;
            const nextLatLng = [lat, lng];

            // Calc speed
            const map = marker._map;
            const prev = marker.getLatLng();
            const meters = map ? map.distance(prev, nextLatLng) : prev.distanceTo(nextLatLng);

            // Assume payload speed is m/s; if it's km/h, convert: mps = kmh / 3.6
            let mps = Number.isFinite(data.speed) && data.speed > 0 ? data.speed : null;

            // Reduce animation duration and clamp between 100ms and 500ms
            // This makes animations faster and prevents long delays
            const durationMs = mps ? Math.max(100, Math.min((meters / mps) * 1000, 500)) : 500;

            try {
                // Apply rotation if heading is valid
                if (typeof marker.setRotationAngle === 'function' && Number.isFinite(data.heading) && data.heading !== -1) {
                    marker.setRotationAngle(data.heading);
                }

                // Move marker with animation
                if (typeof marker.slideTo === 'function') {
                    marker.slideTo(nextLatLng, { duration: durationMs });
                } else {
                    marker.setLatLng(nextLatLng);
                }

                if (typeof this.callback === 'function') {
                    this.callback(output, { nextLatLng, duration: durationMs, mps });
                }

                // Wait for animation to complete
                yield timeout(durationMs + 50);
            } catch (err) {
                debug('MovementTracker EventBuffer error: ' + err.message);
            }
        }

        // Don't clear here - we already cleared at the start
        debug(`[MovementTracker EventBuffer finished processing ${eventsToProcess.length} events]`);
    }
}

export default class MovementTrackerService extends Service {
    @service socket;
    @tracked channels = [];
    @tracked buffers = new Map();

    constructor() {
        super(...arguments);
        this.registerTrackingMarker();
    }

    #getOwner(owner = null) {
        return owner ?? window.Fleetbase ?? getOwner(this);
    }

    #getBuffer(key, model, opts = {}) {
        let buf = this.buffers.get(key);
        if (!buf) {
            buf = new EventBuffer(model, opts);
            buf.start();
            this.buffers.set(key, buf);
        }
        return buf;
    }

    registerTrackingMarker(_owner = null) {
        const owner = this.#getOwner(_owner);
        const emberLeafletService = owner.lookup('service:ember-leaflet');

        if (emberLeafletService) {
            const alreadyRegistered = emberLeafletService.components.find((registeredComponent) => registeredComponent.name === 'leaflet-tracking-marker');
            if (alreadyRegistered) return;

            // we then invoke the `registerComponent` method
            emberLeafletService.registerComponent('leaflet-tracking-marker', {
                as: 'tracking-marker',
                component: LeafletTrackingMarkerComponent,
            });
        }
    }

    closeChannels() {
        this.channels.forEach((channel) => {
            channel.close();
        });
    }

    watch(models = []) {
        models.forEach((model) => {
            this.track(model);
        });
    }

    async track(model, options = {}) {
        // Create socket instance
        const socket = this.socket.instance();

        // Get model type and identifier
        const type = getModelName(model);
        const identifier = model.id;

        // Location events to listen for
        const locationEvents = [`${type}.location_changed`, `${type}.simulated_location_changed`, 'position.changed', 'position.simulated'];

        // Listen on the specific channel
        const channelId = options?.channelId ?? `${type}.${identifier}`;
        const channel = socket.subscribe(channelId);

        // Debug output
        debug(`Tracking movement started for ${type} with id ${identifier}${options?.channelId ? ' on channel ' + channelId : ''}`, model);

        // Track the channel
        this.channels = [...this.channels, channel];

        // Listen to the channel for events
        await channel.listener('subscribe').once();

        // Create event buffer for tracking model
        const eventBuffer = this.#getBuffer(channelId, model, options);

        // Get incoming data and console out
        (async () => {
            for await (let output of channel) {
                const { event } = output;

                if (locationEvents.includes(event)) {
                    eventBuffer.add(output);
                    debug(`Socket Event : ${event} : Added to EventBuffer : ${JSON.stringify(output)}`);
                }
            }
        })();
    }
}
