import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

/**
 * System-wide telematics retention defaults, applied to every company that
 * has not set its own values from Fleet-Ops > Settings > Telematics Data.
 */
export default class AdminTelematicsSettingsComponent extends Component {
    @service fetch;
    @service notifications;
    @tracked eventRetentionDays = 30;
    @tracked eventCompactAfterDays = 7;
    @tracked positionRetentionDays = 90;
    @tracked processedRetentionHours = 24;
    @tracked quarantineRetentionDays = 7;
    @tracked syncRunRetentionDays = 7;
    @tracked logTelemetryActivity = false;

    constructor() {
        super(...arguments);
        this.loadSettings.perform();
    }

    @task *loadSettings() {
        try {
            const settings = yield this.fetch.get('fleet-ops/settings/admin-telematics-settings');
            this.applySettings(settings);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *saveSettings() {
        try {
            const settings = yield this.fetch.post('fleet-ops/settings/admin-telematics-settings', this.settingsPayload);
            this.applySettings(settings);
            this.notifications.success('Telematics retention defaults saved.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    get settingsPayload() {
        return {
            event_retention_days: this.toInteger(this.eventRetentionDays),
            event_compact_after_days: this.toInteger(this.eventCompactAfterDays),
            position_retention_days: this.toInteger(this.positionRetentionDays),
            processed_retention_hours: this.toInteger(this.processedRetentionHours),
            quarantine_retention_days: this.toInteger(this.quarantineRetentionDays),
            sync_run_retention_days: this.toInteger(this.syncRunRetentionDays),
            log_telemetry_activity: Boolean(this.logTelemetryActivity),
        };
    }

    applySettings(settings = {}) {
        if (!settings) {
            return;
        }

        this.eventRetentionDays = settings.event_retention_days ?? this.eventRetentionDays;
        this.eventCompactAfterDays = settings.event_compact_after_days ?? this.eventCompactAfterDays;
        this.positionRetentionDays = settings.position_retention_days ?? this.positionRetentionDays;
        this.processedRetentionHours = settings.processed_retention_hours ?? this.processedRetentionHours;
        this.quarantineRetentionDays = settings.quarantine_retention_days ?? this.quarantineRetentionDays;
        this.syncRunRetentionDays = settings.sync_run_retention_days ?? this.syncRunRetentionDays;
        this.logTelemetryActivity = settings.log_telemetry_activity ?? this.logTelemetryActivity;
    }

    toInteger(value) {
        const number = parseInt(value, 10);

        return Number.isFinite(number) && number > 0 ? number : 0;
    }
}
