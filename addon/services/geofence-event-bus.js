import Service from '@ember/service';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { debug } from '@ember/debug';

/**
 * GeofenceEventBus
 *
 * A singleton service that subscribes to the company-level geofence WebSocket
 * channel and maintains a live feed of geofence events (entered, exited, dwelled).
 *
 * Other components (e.g. the live map, the geofence events panel) inject this
 * service and observe `this.events` to react to incoming events.
 *
 * Usage:
 *   @service geofenceEventBus;
 *
 *   // In a template: {{#each this.geofenceEventBus.events as |event|}} ...
 *   // In JS: this.geofenceEventBus.on('geofence.entered', this.handleEntry);
 */
export default class GeofenceEventBusService extends Service {
    @service socket;
    @service currentUser;
    @service universe;

    /**
     * Live event feed — most recent events at the front.
     * Capped at MAX_EVENTS to prevent unbounded memory growth.
     *
     * @type {Array}
     */
    @tracked events = [];

    /**
     * Whether the service has successfully subscribed to the socket channel.
     *
     * @type {boolean}
     */
    @tracked isSubscribed = false;

    /**
     * Maximum number of events to retain in the live feed.
     */
    MAX_EVENTS = 100;

    /**
     * The geofence event types to listen for.
     */
    GEOFENCE_EVENTS = ['geofence.entered', 'geofence.exited', 'geofence.dwelled'];

    /**
     * Internal map of event type → array of handler functions.
     * Supports the simple pub/sub API.
     *
     * @type {Map}
     */
    #handlers = new Map();

    /**
     * The active socket channel subscription.
     */
    #channel = null;

    /**
     * Subscribe to the company geofence channel.
     * Should be called once after the user session is established.
     *
     * @param {string} companyUuid - The company UUID to subscribe to
     */
    @action
    async subscribe(companyUuid) {
        if (this.isSubscribed || !companyUuid) {
            return;
        }

        try {
            const socketInstance = this.socket.instance();
            const channelId = `company.${companyUuid}`;

            this.#channel = socketInstance.subscribe(channelId);
            if (this.#channel.state !== 'subscribed') {
                await this.#channel.subscribe();
            }

            this.isSubscribed = true;
            debug(`[GeofenceEventBus] Subscribed to channel: ${channelId}`);

            // Start consuming events from the channel
            (async () => {
                for await (const output of this.#channel) {
                    const { event, data } = output;

                    if (this.GEOFENCE_EVENTS.includes(event)) {
                        this.#handleIncomingEvent(event, data);
                    }
                }
            })();
        } catch (error) {
            debug(`[GeofenceEventBus] Failed to subscribe: ${error.message}`);
        }
    }

    /**
     * Unsubscribe from the geofence channel and clear state.
     * Called on user logout or session end.
     */
    @action
    unsubscribe() {
        if (this.#channel) {
            this.#channel.close();
            this.#channel = null;
        }
        this.isSubscribed = false;
        this.events = [];
        this.#handlers.clear();
        debug('[GeofenceEventBus] Unsubscribed.');
    }

    /**
     * Register a handler function for a specific geofence event type.
     *
     * @param {string}   eventType - 'geofence.entered' | 'geofence.exited' | 'geofence.dwelled'
     * @param {Function} handler   - Called with the normalised event object
     */
    on(eventType, handler) {
        if (!this.#handlers.has(eventType)) {
            this.#handlers.set(eventType, []);
        }
        this.#handlers.get(eventType).push(handler);
    }

    /**
     * Unregister a previously registered handler.
     *
     * @param {string}   eventType
     * @param {Function} handler
     */
    off(eventType, handler) {
        if (!this.#handlers.has(eventType)) {
            return;
        }
        const handlers = this.#handlers.get(eventType).filter((h) => h !== handler);
        this.#handlers.set(eventType, handlers);
    }

    /**
     * Clear the live event feed.
     */
    @action
    clearFeed() {
        this.events = [];
    }

    @action
    seedEvents(events = []) {
        this.events = Array.isArray(events) ? events.slice(0, this.MAX_EVENTS).map((event) => ({ ...event, isNew: false })) : [];
    }

    /**
     * Process an incoming geofence event from the WebSocket channel.
     *
     * @param {string} eventType
     * @param {Object} data
     */
    #handleIncomingEvent(eventType, data) {
        const normalised = this.normalizeEvent(eventType, data);

        // Prepend and cap
        this.events = [normalised, ...this.events].slice(0, this.MAX_EVENTS);

        setTimeout(() => {
            this.events = this.events.map((event) => (event.id === normalised.id ? { ...event, isNew: false } : event));
        }, 3000);

        debug(`[GeofenceEventBus] ${eventType} — driver: ${normalised.driverName}, geofence: ${normalised.geofenceName}`);

        // Notify registered handlers
        const handlers = this.#handlers.get(eventType) ?? [];
        handlers.forEach((handler) => {
            try {
                handler(normalised);
            } catch (e) {
                debug(`[GeofenceEventBus] Handler error for ${eventType}: ${e.message}`);
            }
        });

        // Also emit on the universe bus so any component can react
        this.universe.trigger(`fleet-ops.geofence.${eventType.replace('geofence.', '')}`, normalised);
    }

    /**
     * Normalise a raw WebSocket payload into a consistent display object.
     *
     * @param {string} eventType
     * @param {Object} raw
     * @returns {Object}
     */
    normalizeEvent(eventType, raw) {
        const subject = raw.subject ?? null;
        const subjectType = subject?.type ?? (raw.vehicle && !raw.driver ? 'vehicle' : 'driver');
        const subjectName = subject?.name ?? raw.driver?.name ?? raw.vehicle?.name ?? raw.vehicle?.plate ?? 'Unknown Subject';
        return {
            id: raw.id ?? raw.uuid ?? `${Date.now()}-${Math.random()}`,
            eventType,
            occurredAt: raw.occurred_at ?? new Date().toISOString(),
            subjectType,
            subjectName,
            driverName: raw.driver?.name ?? null,
            driverUuid: raw.driver?.uuid ?? null,
            vehicleName: raw.vehicle?.name ?? null,
            vehiclePlate: raw.vehicle?.plate ?? null,
            geofenceName: raw.geofence?.name ?? 'Unknown Geofence',
            geofenceUuid: raw.geofence?.uuid ?? null,
            geofenceType: raw.geofence?.type ?? 'zone',
            orderPublicId: raw.order?.id ?? null,
            latitude: raw.location?.latitude ?? null,
            longitude: raw.location?.longitude ?? null,
            dwellMinutes: raw.dwell_duration_minutes ?? null,
            isNew: true,
        };
    }
}
