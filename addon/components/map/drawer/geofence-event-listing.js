import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class MapDrawerGeofenceEventListingComponent extends Component {
    @service fetch;
    @service geofenceEventBus;
    @service currentUser;

    @tracked isLoading = false;

    constructor() {
        super(...arguments);
        this.geofenceEventBus.subscribe(this.currentUser.companyId);
        this.loadRecentEvents();
    }

    get events() {
        return this.geofenceEventBus.events;
    }

    @action
    async loadRecentEvents() {
        this.isLoading = true;

        try {
            const response = await this.fetch.get('geofences/events', { per_page: 20 });

            if (response?.data) {
                this.geofenceEventBus.seedEvents(response.data.map((event) => this.geofenceEventBus.normalizeEvent(event.event_type, event)));
            }
        } catch (error) {
            // The live socket feed will still populate events if history loading fails.
        } finally {
            this.isLoading = false;
        }
    }

    @action
    badgeColorFor(eventType) {
        const colors = {
            'geofence.entered': 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
            'geofence.exited': 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
            'geofence.dwelled': 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
        };

        return colors[eventType] ?? 'bg-gray-100 text-gray-800';
    }

    @action
    labelFor(eventType) {
        const labels = {
            'geofence.entered': 'Entered',
            'geofence.exited': 'Exited',
            'geofence.dwelled': 'Dwelled',
        };

        return labels[eventType] ?? eventType;
    }

    @action
    iconFor(eventType) {
        const icons = {
            'geofence.entered': 'arrow-right-to-bracket',
            'geofence.exited': 'arrow-right-from-bracket',
            'geofence.dwelled': 'clock',
        };

        return icons[eventType] ?? 'map-pin';
    }

    @action
    subjectIconFor(subjectType) {
        return subjectType === 'vehicle' ? 'car' : 'id-card';
    }

    @action
    geofenceTypeLabel(type) {
        if (type === 'service_area') {
            return 'Service Area';
        }

        if (type === 'zone') {
            return 'Zone';
        }

        return type ?? 'Geofence';
    }

    @action
    hasSecondaryMeta(event) {
        return Boolean(event.vehiclePlate || event.orderPublicId || event.dwellMinutes || event.latitude || event.longitude);
    }

    @action
    clearFeed() {
        this.geofenceEventBus.clearFeed();
    }
}
