import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/** Organization history preferences, subject to the system retention policy. */
export default class SettingsTelematicsController extends Controller {
    @service fetch;
    @service notifications;
    @service intl;

    @tracked eventRetentionDays = 30;
    @tracked positionRetentionDays = 90;
    @tracked useDefaultEventRetention = true;
    @tracked useDefaultPositionRetention = true;
    @tracked defaults = {};
    @tracked policy = {};
    @tracked settingsLoaded = false;

    get eventRetentionMinimum() {
        return this.policy.max_event_retention_days > 0 ? 1 : 0;
    }

    get eventRetentionMaximum() {
        return this.policy.max_event_retention_days > 0 ? this.policy.max_event_retention_days : 3650;
    }

    get positionRetentionMinimum() {
        return this.policy.max_position_retention_days > 0 ? 1 : 0;
    }

    get positionRetentionMaximum() {
        return this.policy.max_position_retention_days > 0 ? this.policy.max_position_retention_days : 3650;
    }

    get eventRetentionPolicyHelp() {
        return this.retentionPolicyHelp(this.policy.max_event_retention_days);
    }

    get positionRetentionPolicyHelp() {
        return this.retentionPolicyHelp(this.policy.max_position_retention_days);
    }

    get settingsPayload() {
        return {
            event_retention_days: this.useDefaultEventRetention ? null : this.eventRetentionDays,
            position_retention_days: this.useDefaultPositionRetention ? null : this.positionRetentionDays,
        };
    }

    @task({ restartable: true }) *getSettings() {
        this.settingsLoaded = false;

        try {
            const settings = yield this.fetch.get('fleet-ops/settings/telematics-settings');
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
            const settings = yield this.fetch.post('fleet-ops/settings/telematics-settings', this.settingsPayload);
            this.applySettings(settings);
            this.notifications.success(this.intl.t('settings.telematics.settings-saved'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action setUseDefaultEventRetention(enabled) {
        this.useDefaultEventRetention = enabled;
        if (enabled) {
            this.eventRetentionDays = this.defaults.event_retention_days ?? this.eventRetentionDays;
        }
    }

    @action setUseDefaultPositionRetention(enabled) {
        this.useDefaultPositionRetention = enabled;
        if (enabled) {
            this.positionRetentionDays = this.defaults.position_retention_days ?? this.positionRetentionDays;
        }
    }

    applySettings(settings = {}) {
        const has = (object, key) => Object.prototype.hasOwnProperty.call(object ?? {}, key);
        const hasHistoryMetadata = ['event_retention_days', 'position_retention_days'].every(
            (key) => has(settings?.preferences, key) && has(settings?.defaults, key) && has(settings?.policy, `max_${key}`)
        );

        if (!hasHistoryMetadata) {
            throw new Error(this.intl.t('settings.telematics.settings-unavailable'));
        }

        this.eventRetentionDays = settings.event_retention_days ?? this.eventRetentionDays;
        this.positionRetentionDays = settings.position_retention_days ?? this.positionRetentionDays;
        this.useDefaultEventRetention = (settings.preferences?.event_retention_days ?? null) === null;
        this.useDefaultPositionRetention = (settings.preferences?.position_retention_days ?? null) === null;
        this.defaults = settings.defaults ?? this.defaults;
        this.policy = settings.policy ?? this.policy;
    }

    retentionPolicyHelp(maximum) {
        return maximum > 0
            ? this.intl.t('settings.telematics.history-policy-maximum', { days: maximum })
            : this.intl.t('settings.telematics.history-policy-unlimited');
    }
}
