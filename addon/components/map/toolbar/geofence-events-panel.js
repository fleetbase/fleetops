import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { later } from '@ember/runloop';

/**
 * GeofenceEventsPanel
 *
 * A live map toolbar panel that displays a real-time stream of geofence
 * events (entered, exited, dwelled) as they occur. Events are received
 * via the WebSocket channel and displayed in a scrollable feed with
 * colour-coded badges.
 *
 * The panel also provides a link to the full geofence event log.
 */
export default class GeofenceEventsPanelComponent extends Component {
    @service store;
    @service fetch;
    @service socket;

    /**
     * Live event feed — most recent events at the top.
     * Capped at 50 entries to avoid unbounded memory growth.
     *
     * @type {Array}
     */
    @tracked events = [];

    /**
     * Whether the panel is currently loading historical events.
     *
     * @type {boolean}
     */
    @tracked isLoading = false;

    /**
     * Maximum number of events to keep in the live feed.
     */
    MAX_EVENTS = 50;

    constructor() {
        super(...arguments);
        this.loadRecentEvents();
        this.subscribeToGeofenceEvents();
    }

    /**
     * Load the most recent geofence events from the API to populate the
     * feed on initial render.
     */
    @action
    async loadRecentEvents() {
        this.isLoading = true;
        try {
            const response = await this.fetch.get('geofences/events', { per_page: 20 });
            if (response && response.data) {
                this.events = response.data.map(this.normalizeEvent);
            }
        } catch (error) {
            // Silently fail — the panel will populate as live events arrive
        } finally {
            this.isLoading = false;
        }
    }

    /**
     * Subscribe to the geofence.* WebSocket events broadcast by the server.
     * Incoming events are prepended to the live feed.
     */
    subscribeToGeofenceEvents() {
        if (!this.socket) {
            return;
        }

        const channelId = this.args.channelId;
        if (!channelId) {
            return;
        }

        // Listen for all three geofence event types
        ['geofence.entered', 'geofence.exited', 'geofence.dwelled'].forEach((eventType) => {
            this.socket.listen(channelId, eventType, (data) => {
                this.onGeofenceEvent(data);
            });
        });
    }

    /**
     * Handle an incoming geofence WebSocket event.
     *
     * @param {Object} data - The event payload from the server
     */
    @action
    onGeofenceEvent(data) {
        const event = this.normalizeEvent(data);

        // Prepend to the feed and cap at MAX_EVENTS
        this.events = [event, ...this.events].slice(0, this.MAX_EVENTS);

        // Flash the new event row briefly to draw attention
        later(() => {
            event.isNew = false;
        }, 3000);
    }

    /**
     * Normalise a raw event payload into a display-friendly object.
     *
     * @param {Object} raw
     * @returns {Object}
     */
    normalizeEvent(raw) {
        return {
            id:           raw.id ?? raw.uuid ?? Math.random().toString(36),
            eventType:    raw.event_type,
            occurredAt:   raw.occurred_at,
            driverName:   raw.driver?.name ?? 'Unknown Driver',
            geofenceName: raw.geofence?.name ?? 'Unknown Geofence',
            geofenceType: raw.geofence?.type ?? 'zone',
            dwellMinutes: raw.dwell_duration_minutes ?? null,
            isNew:        true,
        };
    }

    /**
     * Returns a Tailwind CSS badge colour class for the given event type.
     *
     * @param {string} eventType
     * @returns {string}
     */
    @action
    badgeColorFor(eventType) {
        const colors = {
            'geofence.entered': 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
            'geofence.exited':  'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
            'geofence.dwelled': 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
        };
        return colors[eventType] ?? 'bg-gray-100 text-gray-800';
    }

    /**
     * Returns a human-readable label for the given event type.
     *
     * @param {string} eventType
     * @returns {string}
     */
    @action
    labelFor(eventType) {
        const labels = {
            'geofence.entered': 'Entered',
            'geofence.exited':  'Exited',
            'geofence.dwelled': 'Dwelled',
        };
        return labels[eventType] ?? eventType;
    }

    /**
     * Clear the live feed.
     */
    @action
    clearFeed() {
        this.events = [];
    }
}
