import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class OrderDetailsTrackingComponent extends Component {
    @service orderActions;

    constructor() {
        super(...arguments);
        this.loadTrackerData.perform();
    }

    @task *loadTrackerData() {
        if (!this.args.resource || typeof this.args.resource.loadTrackerData !== 'function') {
            return;
        }

        try {
            yield this.args.resource.loadTrackerData();
        } catch (err) {
            debug('Failed to load order tracker data: ' + err.message);
        }
    }

    get trackerData() {
        return this.args.resource?.tracker_data;
    }

    get hasTrackerData() {
        return Boolean(this.trackerData);
    }

    get activeEtaSeconds() {
        return this.trackerData?.eta?.active_stop_seconds;
    }

    get hasActiveEta() {
        return this.activeEtaSeconds !== null && this.activeEtaSeconds !== undefined;
    }

    get isDueNow() {
        return this.hasActiveEta && Number(this.activeEtaSeconds) <= 0;
    }

    get hasCompletionEta() {
        return Boolean(this.trackerData?.eta?.completion_at);
    }

    get hasRemainingDistance() {
        return this.trackerData?.route?.distance_m !== null && this.trackerData?.route?.distance_m !== undefined;
    }

    get driverSignal() {
        const trackerData = this.trackerData;

        if (!trackerData?.driver?.location) {
            return 'Missing';
        }

        if (trackerData?.insights?.is_location_stale) {
            return 'Stale';
        }

        return trackerData?.driver?.online ? 'Live' : 'Offline';
    }

    get driverSignalClass() {
        switch (this.driverSignal) {
            case 'Live':
                return 'text-green-600 dark:text-green-400';
            case 'Stale':
                return 'text-yellow-600 dark:text-yellow-400';
            case 'Missing':
                return 'text-red-600 dark:text-red-400';
            default:
                return 'text-gray-600 dark:text-gray-300';
        }
    }

    get routeQualityItems() {
        const trackerData = this.trackerData;

        if (!trackerData) {
            return [];
        }

        const items = [`${this.humanize(trackerData.provider)} route`];

        if (trackerData.confidence) {
            items.push(`${this.humanize(trackerData.confidence)} confidence`);
        }

        if (trackerData.fallback_provider) {
            items.push(`Fallback: ${this.humanize(trackerData.fallback_provider)}`);
        }

        return items;
    }

    get operatorWarning() {
        const trackerData = this.trackerData;

        if (!trackerData) {
            return null;
        }

        if (!trackerData?.driver?.location) {
            return 'Driver location is missing, so ETA accuracy may be limited.';
        }

        if (trackerData?.insights?.is_location_stale) {
            return 'Driver location is stale. ETA may not reflect the latest movement.';
        }

        if (trackerData.fallback_provider) {
            return `Using ${this.humanize(trackerData.fallback_provider)} fallback because the preferred tracking provider was unavailable.`;
        }

        if (trackerData.confidence && trackerData.confidence !== 'high') {
            return `${this.humanize(trackerData.confidence)} confidence ETA. Treat the estimate as directional.`;
        }

        if ((trackerData.warnings ?? []).some((warning) => String(warning).startsWith('provider_failed'))) {
            return 'Tracking provider returned an error. Showing the best available estimate.';
        }

        return null;
    }

    humanize(value) {
        return String(value ?? '')
            .replace(/[_:-]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .replace(/\b\w/g, (character) => character.toUpperCase());
    }
}
