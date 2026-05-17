import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class OrderDetailsTrackingComponent extends Component {
    @service orderActions;
    @service fetch;
    @service notifications;

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

    get smartAdjustedEtaSeconds() {
        return (
            this.firstPositiveNumber(this.activeEtaSeconds) ??
            this.firstPositiveNumber(this.trackerData?.route?.duration_in_traffic_s) ??
            this.firstPositiveNumber(this.trackerData?.route?.duration_s) ??
            this.firstPositiveNumber(this.reportedEtaSeconds) ??
            null
        );
    }

    get hasSmartAdjustedEta() {
        return this.smartAdjustedEtaSeconds !== null && this.smartAdjustedEtaSeconds !== undefined;
    }

    get smartAdjustedEtaUnavailableLabel() {
        if (this.driverSignal === 'Missing' || this.driverSignal === 'Stale') {
            return 'Pending GPS fix';
        }

        return 'Pending start';
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

    get driverStatusLabel() {
        switch (this.driverSignal) {
            case 'Live':
                return 'Driver live';
            case 'Stale':
                return 'Driver stale';
            case 'Missing':
                return 'Driver missing GPS';
            default:
                return 'Driver offline';
        }
    }

    get hasDriverLocationAge() {
        return this.trackerData?.driver?.location_age_seconds !== null && this.trackerData?.driver?.location_age_seconds !== undefined;
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

    get confidenceLabel() {
        return this.humanize(this.trackerData?.confidence || 'unknown');
    }

    get confidencePercent() {
        const score = this.trackerData?.confidence_score ?? this.trackerData?.confidence_percent ?? this.trackerData?.confidence_percentage;

        if (score !== null && score !== undefined && !Number.isNaN(Number(score))) {
            return Math.max(0, Math.min(100, Math.round(Number(score))));
        }

        switch (this.trackerData?.confidence) {
            case 'high':
                return 92;
            case 'medium':
                return 68;
            case 'low':
                return 34;
            default:
                return 0;
        }
    }

    get providerLabel() {
        return this.humanize(this.trackerData?.provider);
    }

    get fallbackProviderLabel() {
        return this.humanize(this.trackerData?.fallback_provider);
    }

    get confidenceToneClass() {
        switch (this.trackerData?.confidence) {
            case 'high':
                return 'tracking-intelligence-pill--good';
            case 'medium':
                return 'tracking-intelligence-pill--warn';
            case 'low':
                return 'tracking-intelligence-pill--bad';
            default:
                return 'tracking-intelligence-pill--muted';
        }
    }

    get confidenceSegments() {
        const confidence = this.trackerData?.confidence;
        const litCount = confidence === 'high' ? 5 : confidence === 'medium' ? 3 : confidence === 'low' ? 2 : 1;

        return Array.from({ length: 5 }, (_, index) => ({
            lit: index < litCount,
        }));
    }

    get activeStopIndex() {
        const stops = this.trackerData?.stops ?? [];
        const activeStop = this.trackerData?.active_stop;
        const index = stops.findIndex((stop) => this.matchesStop(stop, activeStop));

        return index >= 0 ? index + 1 : null;
    }

    get totalStops() {
        return this.trackerData?.stops?.length ?? 0;
    }

    get activeStopLabel() {
        if (!this.activeStopIndex || !this.totalStops) {
            return 'NOW HEADING TO';
        }

        return `NOW HEADING TO - STOP ${this.activeStopIndex} OF ${this.totalStops}`;
    }

    get activeStopMarkerLabel() {
        const activeStop = this.trackerData?.active_stop;

        if (activeStop?.type === 'pickup') {
            return 'P';
        }

        if (activeStop?.type === 'dropoff') {
            return 'D';
        }

        return this.activeStopIndex ?? '•';
    }

    get reportedEtaSeconds() {
        const activeStop = this.trackerData?.active_stop;
        const eta = this.args.resource?.eta ?? {};

        return activeStop?.eta_seconds ?? eta?.[activeStop?.id] ?? eta?.[activeStop?.uuid] ?? eta?.[activeStop?.public_id] ?? null;
    }

    get displayedReportedEtaSeconds() {
        return (
            this.firstPositiveNumber(this.reportedEtaSeconds) ??
            this.firstPositiveNumber(this.activeEtaSeconds) ??
            this.firstPositiveNumber(this.trackerData?.route?.duration_in_traffic_s) ??
            this.firstPositiveNumber(this.trackerData?.route?.duration_s) ??
            null
        );
    }

    get hasDisplayedReportedEta() {
        return this.displayedReportedEtaSeconds !== null && this.displayedReportedEtaSeconds !== undefined;
    }

    get isReportedEtaUntrusted() {
        const trackerData = this.trackerData;

        if (!trackerData) {
            return false;
        }

        return !trackerData?.driver?.location || trackerData?.insights?.is_location_stale || trackerData.fallback_provider || (trackerData.confidence && trackerData.confidence !== 'high');
    }

    get reportedEtaWarning() {
        if (!this.isReportedEtaUntrusted) {
            return 'Reported from existing route data';
        }

        return this.operatorWarning ?? 'Reported ETA may not reflect the latest tracking signal.';
    }

    get warningsCount() {
        return (this.trackerData?.warnings ?? []).length;
    }

    get diagnosticsSummaryLabel() {
        return this.warningsCount === 1 ? '1 warning' : `${this.warningsCount} warnings`;
    }

    get totalProgressPercentage() {
        const percentage = Number(this.trackerData?.progress?.percentage);

        if (Number.isFinite(percentage)) {
            return Math.max(0, Math.min(100, percentage));
        }

        const stops = this.trackerData?.stops ?? [];
        const completedStops = stops.filter((stop) => stop.completed).length;

        if (stops.length) {
            return Math.max(0, Math.min(100, Math.round((completedStops / stops.length) * 100)));
        }

        return 0;
    }

    get totalProgressStyle() {
        const percentage = this.hasRemainingDistance && this.totalProgressPercentage === 0 ? 2 : this.totalProgressPercentage;

        return `width: ${percentage}%;`;
    }

    get currentLeg() {
        return this.trackerData?.route?.legs?.[0] ?? null;
    }

    get hasCurrentLegDistance() {
        return this.currentLeg?.distance_m !== null && this.currentLeg?.distance_m !== undefined;
    }

    get currentLegProgressPercentage() {
        const explicit = Number(this.currentLeg?.progress_percentage ?? this.trackerData?.progress?.active_leg_percentage);

        if (Number.isFinite(explicit)) {
            return Math.max(0, Math.min(100, explicit));
        }

        if (this.driverSignal === 'Missing') {
            return 0;
        }

        if (this.driverSignal === 'Stale') {
            return 18;
        }

        return Math.max(8, Math.min(92, this.totalProgressPercentage));
    }

    get currentLegProgressStyle() {
        return `width: ${this.currentLegProgressPercentage}%;`;
    }

    get hasWarnings() {
        return (this.trackerData?.warnings ?? []).length > 0;
    }

    get diagnostics() {
        const trackerData = this.trackerData;

        if (!trackerData) {
            return [];
        }

        return [
            { label: 'Provider', value: this.humanize(trackerData.provider) },
            { label: 'Fallback', value: trackerData.fallback_provider ? this.humanize(trackerData.fallback_provider) : 'No' },
            { label: 'Traffic aware', value: trackerData.options?.traffic_enabled ? 'Yes' : 'No' },
            { label: 'Confidence', value: this.confidenceLabel },
            { label: 'Driver signal', value: this.driverSignal },
            { label: 'Warnings', value: String((trackerData.warnings ?? []).length) },
        ];
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

    firstPositiveNumber(value) {
        const number = Number(value);

        return Number.isFinite(number) && number > 0 ? number : null;
    }

    matchesStop(stop, activeStop) {
        if (!stop || !activeStop) {
            return false;
        }

        return stop.uuid === activeStop.uuid || stop.public_id === activeStop.public_id || stop.id === activeStop.id;
    }

    @task *pingDriver() {
        const order = this.args.resource;

        if (!order) {
            return;
        }

        try {
            yield this.fetch.post(`orders/${order.id}/ping-driver`);
            this.notifications.success('Driver app ping sent.');
        } catch (err) {
            this.notifications.error(err.message ?? 'Unable to ping driver app.');
        }
    }
}
