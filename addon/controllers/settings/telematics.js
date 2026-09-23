import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';

/**
 * Settings::TelematicsController
 *
 * Company-level data retention for telematics ingestion:
 *   - How long device events are kept, and when their raw provider payloads are stripped
 *   - How long positions are kept
 *   - How long processed and quarantined delivery envelopes and sync runs are kept
 *   - Whether telemetry-driven saves are written to the activity log
 * plus a read-only view of the rows each table holds and an on-demand cleanup run.
 */
export default class SettingsTelematicsController extends Controller {
    @service fetch;
    @service notifications;
    @service intl;
    @service currentUser;
    @service modalsManager;

    /** Days to keep device events; 0 keeps them forever. */
    @tracked eventRetentionDays = 30;

    /** Days after which raw payload/meta blobs are stripped from kept events; 0 disables compaction. */
    @tracked eventCompactAfterDays = 7;

    /** Days to keep positions; 0 keeps them forever. */
    @tracked positionRetentionDays = 90;

    /** Hours to keep processed delivery envelopes. */
    @tracked processedRetentionHours = 24;

    /** Days to keep quarantined delivery envelopes. */
    @tracked quarantineRetentionDays = 7;

    /** Days to keep sync run diagnostics. */
    @tracked syncRunRetentionDays = 7;

    /** Whether telemetry-driven saves are written to the activity log. */
    @tracked logTelemetryActivity = false;

    /** System defaults and clamp limits reported by the API. */
    @tracked defaults = {};
    @tracked limits = {};

    /** Per-table storage usage reported by the API. */
    @tracked usage = null;

    get isAdmin() {
        return this.currentUser.isAdmin === true;
    }

    /** Compaction is pointless once events are deleted at the same age or sooner. */
    get compactionIneffective() {
        const retention = Number(this.eventRetentionDays);
        const compactAfter = Number(this.eventCompactAfterDays);

        return retention > 0 && compactAfter > 0 && compactAfter >= retention;
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
            event_compact_after_days: this.toInteger(this.eventCompactAfterDays),
            position_retention_days: this.toInteger(this.positionRetentionDays),
            processed_retention_hours: this.toInteger(this.processedRetentionHours),
            quarantine_retention_days: this.toInteger(this.quarantineRetentionDays),
            sync_run_retention_days: this.toInteger(this.syncRunRetentionDays),
            log_telemetry_activity: Boolean(this.logTelemetryActivity),
        };
    }

    constructor() {
        super(...arguments);
        this.getSettings.perform();
        this.loadUsage.perform();
    }

    /**
     * Load retention settings from the backend.
     */
    @task *getSettings() {
        try {
            const settings = yield this.fetch.get('fleet-ops/settings/telematics-settings');
            this.applySettings(settings);
        } catch {
            // Settings may not exist yet — use defaults silently
        }
    }

    /**
     * Save retention settings to the backend.
     */
    @task *saveSettings() {
        try {
            const settings = yield this.fetch.post('fleet-ops/settings/telematics-settings', this.settingsPayload);
            this.applySettings(settings);
            this.notifications.success(this.intl.t('settings.telematics.settings-saved'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Load per-table storage usage for the current company.
     */
    @task *loadUsage() {
        try {
            this.usage = yield this.fetch.get('fleet-ops/settings/telematics-storage-usage');
        } catch {
            this.usage = null;
        }
    }

    /**
     * Queue an immediate retention run for the current company, then refresh usage.
     */
    @task *runCleanup() {
        try {
            yield this.fetch.post('fleet-ops/settings/telematics-retention/run');
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
        this.defaults = settings.defaults ?? this.defaults;
        this.limits = settings.limits ?? this.limits;
    }

    toInteger(value) {
        const number = parseInt(value, 10);

        return Number.isFinite(number) && number > 0 ? number : 0;
    }
}
