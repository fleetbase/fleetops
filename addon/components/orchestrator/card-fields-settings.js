import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

/**
 * Orchestrator::CardFieldsSettings
 *
 * Settings panel for configuring which fields appear on order cards in the
 * workbench. Fields are grouped by order config so the dispatcher can see
 * exactly which custom fields belong to which order type.
 *
 * Saves to company settings key: fleetops.orchestrator_card_fields
 * Shape: { standard: string[], byConfig: { [configUuid]: string[] }, meta: string[] }
 *
 * @arg onSaved - Optional action called after settings are saved
 */
export default class OrchestratorCardFieldsSettingsComponent extends Component {
    @service fetch;
    @service notifications;
    @service intl;

    /** Available order configs with their custom field definitions. */
    @tracked orderConfigs = [];

    /** Available meta keys discovered from recent orders. */
    @tracked availableMetaKeys = [];

    /** Current saved settings. */
    @tracked settings = {
        standard: ['pickup', 'dropoff', 'scheduled_at', 'customer', 'driver_assigned', 'vehicle_assigned', 'created_at'],
        byConfig: {},
        meta: [],
    };

    @tracked isLoaded = false;

    constructor() {
        super(...arguments);
        this.loadData.perform();
    }

    get standardFieldOptions() {
        return [
            { key: 'tracking', label: this.intl.t('orchestrator.field-tracking') },
            { key: 'status', label: this.intl.t('orchestrator.field-status') },
            { key: 'scheduled_at', label: this.intl.t('orchestrator.field-scheduled-at') },
            { key: 'customer', label: this.intl.t('orchestrator.field-customer') },
            { key: 'type', label: this.intl.t('orchestrator.field-type') },
            { key: 'notes', label: this.intl.t('orchestrator.field-notes') },
            { key: 'priority', label: this.intl.t('orchestrator.field-priority') },
            { key: 'dropoff', label: this.intl.t('orchestrator.dropoff') },
            { key: 'pickup', label: this.intl.t('orchestrator.pickup') },
            { key: 'driver_assigned', label: this.intl.t('orchestrator.driver-assigned') },
            { key: 'vehicle_assigned', label: this.intl.t('orchestrator.vehicle-assigned') },
            { key: 'created_at', label: this.intl.t('orchestrator.created') },
        ];
    }

    @task *loadData() {
        try {
            const [configsResult, settingsResult] = yield Promise.all([
                this.fetch.get('fleet-ops/orchestrator/order-config-fields'),
                this.fetch.get('fleet-ops/settings/orchestrator-card-fields').catch(() => null),
            ]);

            // Backend returns { configs: [...], meta_keys: [...] }
            this.orderConfigs = configsResult?.configs ?? [];
            this.availableMetaKeys = configsResult?.meta_keys ?? [];

            if (settingsResult?.settings) {
                this.settings = {
                    standard: settingsResult.settings.standard ?? this.settings.standard,
                    byConfig: settingsResult.settings.byConfig ?? {},
                    meta: settingsResult.settings.meta ?? [],
                };
            }
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.isLoaded = true;
        }
    }

    @task *saveSettings() {
        try {
            yield this.fetch.post('fleet-ops/settings/orchestrator-card-fields', {
                settings: this.settings,
            });
            this.notifications.success(this.intl.t('orchestrator.card-fields-saved'));
            this.args.onSaved?.();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action toggleStandardField(key) {
        const current = this.settings.standard ?? [];
        const updated = current.includes(key) ? current.filter((k) => k !== key) : [...current, key];
        this.settings = { ...this.settings, standard: updated };
    }

    // configUuid here is the real UUID (not public_id) so it matches order.order_config_uuid
    @action toggleConfigField(configUuid, fieldKey) {
        const byConfig = { ...(this.settings.byConfig ?? {}) };
        const current = byConfig[configUuid] ?? [];
        byConfig[configUuid] = current.includes(fieldKey) ? current.filter((k) => k !== fieldKey) : [...current, fieldKey];
        this.settings = { ...this.settings, byConfig };
    }

    @action toggleMetaKey(key) {
        const current = this.settings.meta ?? [];
        const updated = current.includes(key) ? current.filter((k) => k !== key) : [...current, key];
        this.settings = { ...this.settings, meta: updated };
    }

    /**
     * These three methods are called with arguments from HBS so they must be
     * decorated with @action to satisfy Glimmer's template invocation rules.
     */
    @action isStandardSelected(key) {
        return (this.settings.standard ?? []).includes(key);
    }

    // configUuid here is the real UUID (not public_id) so it matches order.order_config_uuid
    @action isConfigFieldSelected(configUuid, fieldKey) {
        return (this.settings.byConfig?.[configUuid] ?? []).includes(fieldKey);
    }

    @action isMetaKeySelected(key) {
        return (this.settings.meta ?? []).includes(key);
    }
}
