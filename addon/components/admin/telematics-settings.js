import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';

/**
 * System-wide telematics policy, storage diagnostics and operational controls.
 */
export default class AdminTelematicsSettingsComponent extends Component {
    @service fetch;
    @service notifications;
    @service intl;
    @service modalsManager;
    @tracked eventRetentionDays = 30;
    @tracked maxEventRetentionDays = 0;
    @tracked eventCompactAfterDays = 7;
    @tracked positionRetentionDays = 90;
    @tracked maxPositionRetentionDays = 0;
    @tracked processedRetentionHours = 24;
    @tracked quarantineRetentionDays = 7;
    @tracked syncRunRetentionDays = 7;
    @tracked logTelemetryActivity = false;
    @tracked settingsLoaded = false;
    @tracked usage = null;

    constructor() {
        super(...arguments);
        this.loadSettings.perform();
        this.loadUsage.perform();
    }

    @task({ drop: true }) *loadSettings() {
        try {
            const settings = yield this.fetch.get('fleet-ops/settings/admin-telematics-settings');
            this.applySettings(settings);
            this.settingsLoaded = true;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *saveSettings() {
        if (!this.settingsLoaded) {
            return;
        }

        try {
            const settings = yield this.fetch.post('fleet-ops/settings/admin-telematics-settings', this.settingsPayload);
            this.applySettings(settings);
            this.notifications.success('System telematics settings saved.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /** Load system-wide storage usage across all organizations. */
    @task({ drop: true }) *loadUsage() {
        const abortController = new AbortController();
        const requestTimeout = setTimeout(() => abortController.abort(), 30000);

        try {
            // Pass the signal as request data; fetch.get options do not forward it.
            const usage = yield this.fetch.request('fleet-ops/settings/telematics-storage-usage?include_payload_counts=0', 'GET', { signal: abortController.signal });
            if (usage?.scope !== 'system') {
                throw new Error(this.intl.t('settings.telematics.usage-unavailable'));
            }
            this.usage = usage;
        } catch (error) {
            this.notifications.serverError(abortController.signal.aborted ? new Error(this.intl.t('settings.telematics.usage-unavailable')) : error);
        } finally {
            clearTimeout(requestTimeout);
            abortController.abort();
        }
    }

    /** Queue cleanup for all organizations using the saved policy. */
    @task({ drop: true }) *runCleanup() {
        try {
            const cleanup = yield this.fetch.post('fleet-ops/settings/telematics-retention/run');
            if (cleanup?.scope !== 'system') {
                throw new Error(this.intl.t('settings.telematics.cleanup-unavailable'));
            }
            this.notifications.success(this.intl.t('settings.telematics.cleanup-queued'));
            yield timeout(5000);
            yield this.loadUsage.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action confirmRunCleanup() {
        return this.modalsManager.confirm({
            title: this.intl.t('settings.telematics.run-cleanup'),
            body: this.intl.t('settings.telematics.run-cleanup-confirm'),
            acceptButtonText: this.intl.t('settings.telematics.run-cleanup'),
            acceptButtonIcon: 'broom',
            onConfirm: () => this.runCleanup.perform(),
        });
    }

    get usageRows() {
        const tables = this.usage?.tables ?? {};

        return ['device_events', 'positions', 'telematic_deliveries', 'telematic_sync_runs']
            .filter((table) => tables[table])
            .map((table) => ({ table, label: this.intl.t(`settings.telematics.tables.${table}`), ...tables[table] }));
    }

    get settingsPayload() {
        return {
            event_retention_days: this.toInteger(this.eventRetentionDays),
            max_event_retention_days: this.maxEventRetentionDays,
            event_compact_after_days: this.toInteger(this.eventCompactAfterDays),
            position_retention_days: this.toInteger(this.positionRetentionDays),
            max_position_retention_days: this.maxPositionRetentionDays,
            processed_retention_hours: this.toInteger(this.processedRetentionHours),
            quarantine_retention_days: this.toInteger(this.quarantineRetentionDays),
            sync_run_retention_days: this.toInteger(this.syncRunRetentionDays),
            log_telemetry_activity: Boolean(this.logTelemetryActivity),
        };
    }

    applySettings(settings = {}) {
        if (!Object.prototype.hasOwnProperty.call(settings ?? {}, 'max_event_retention_days') || !Object.prototype.hasOwnProperty.call(settings ?? {}, 'max_position_retention_days')) {
            throw new Error(this.intl.t('settings.telematics.settings-unavailable'));
        }

        this.eventRetentionDays = settings.event_retention_days ?? this.eventRetentionDays;
        this.maxEventRetentionDays = settings.max_event_retention_days ?? this.maxEventRetentionDays;
        this.eventCompactAfterDays = settings.event_compact_after_days ?? this.eventCompactAfterDays;
        this.positionRetentionDays = settings.position_retention_days ?? this.positionRetentionDays;
        this.maxPositionRetentionDays = settings.max_position_retention_days ?? this.maxPositionRetentionDays;
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
