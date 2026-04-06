import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * Settings::SchedulingController
 *
 * Manages global scheduling settings for Fleet-Ops:
 *   - Default schedule horizon (how many days ahead to materialise shifts)
 *   - Default shift duration
 *   - HOS (Hours of Service) daily/weekly limits
 *   - Whether to auto-activate schedules on first shift creation
 *   - Whether to notify drivers of new/changed shifts
 *   - Reusable schedule templates library
 */
export default class SettingsSchedulingController extends Controller {
    @service fetch;
    @service notifications;
    @service intl;
    @service store;
    @service modalsManager;

    /** How many days ahead to materialise schedule items (default 60) */
    @tracked horizonDays = 60;

    /** Default shift duration in hours (default 8) */
    @tracked defaultShiftDuration = 8;

    /** HOS daily driving limit in hours (default 11) */
    @tracked hosDailyLimit = 11;

    /** HOS weekly driving limit in hours (default 70) */
    @tracked hosWeeklyLimit = 70;

    /** Whether to auto-activate a draft schedule when the first shift is added */
    @tracked autoActivateSchedule = true;

    /** Whether to notify drivers when shifts are created or modified */
    @tracked notifyDriversOnShiftChange = false;

    /** Reusable (library) schedule templates */
    @tracked scheduleTemplates = [];

    get templateActionButtons() {
        return [
            {
                type: 'primary',
                icon: 'plus',
                iconPrefix: 'fas',
                text: this.intl.t('settings.scheduling.new-template'),
                onClick: this.createTemplate,
            },
        ];
    }

    constructor() {
        super(...arguments);
        this.getSettings.perform();
        this.loadTemplates.perform();
    }

    /**
     * Load scheduling settings from the backend.
     */
    @task *getSettings() {
        try {
            const settings = yield this.fetch.get('fleet-ops/settings/scheduling-settings');
            if (settings) {
                this.horizonDays = settings.horizon_days ?? 60;
                this.defaultShiftDuration = settings.default_shift_duration ?? 8;
                this.hosDailyLimit = settings.hos_daily_limit ?? 11;
                this.hosWeeklyLimit = settings.hos_weekly_limit ?? 70;
                this.autoActivateSchedule = settings.auto_activate_schedule ?? true;
                this.notifyDriversOnShiftChange = settings.notify_drivers_on_shift_change ?? false;
            }
        } catch {
            // Settings may not exist yet — use defaults silently
        }
    }

    /**
     * Save scheduling settings to the backend.
     */
    @task *saveSettings() {
        try {
            yield this.fetch.post('fleet-ops/settings/scheduling-settings', {
                horizon_days: this.horizonDays,
                default_shift_duration: this.defaultShiftDuration,
                hos_daily_limit: this.hosDailyLimit,
                hos_weekly_limit: this.hosWeeklyLimit,
                auto_activate_schedule: this.autoActivateSchedule,
                notify_drivers_on_shift_change: this.notifyDriversOnShiftChange,
            });
            this.notifications.success(this.intl.t('settings.scheduling.settings-saved'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Load reusable (library) schedule templates — those without a subject_uuid.
     */
    @task *loadTemplates() {
        try {
            const templates = yield this.store.query('schedule-template', { is_library: true });
            this.scheduleTemplates = templates.toArray();
        } catch {
            this.scheduleTemplates = [];
        }
    }

    /**
     * Open the create-template modal.
     */
    @action createTemplate() {
        this.modalsManager.show('modals/add-driver-shift', {
            title: this.intl.t('settings.scheduling.new-template'),
            isLibraryTemplate: true,
            onConfirm: () => this.loadTemplates.perform(),
        });
    }

    /**
     * Open the edit-template modal.
     */
    @action editTemplate(template) {
        this.modalsManager.show('modals/add-driver-shift', {
            title: this.intl.t('settings.scheduling.edit-template'),
            isLibraryTemplate: true,
            template,
            onConfirm: () => this.loadTemplates.perform(),
        });
    }

    /**
     * Delete a schedule template after confirmation.
     */
    @action async deleteTemplate(template) {
        await this.modalsManager.confirm({
            title: this.intl.t('settings.scheduling.delete-template'),
            body: this.intl.t('settings.scheduling.delete-template-confirm', { name: template.name }),
            onConfirm: async () => {
                try {
                    await template.destroyRecord();
                    this.scheduleTemplates = this.scheduleTemplates.filter((t) => t.id !== template.id);
                    this.notifications.success(this.intl.t('settings.scheduling.template-deleted'));
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }
}
