import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

/**
 * How adhoc orders, those without an assigned driver, are offered to drivers by default. Order
 * configs and single orders can choose otherwise.
 */
export default class SettingsDispatchController extends Controller {
    @service fetch;
    @service notifications;
    @service intl;
    @tracked adhocDistance = 6000;
    @tracked adhocStrategy = 'sequential';
    @tracked adhocOfferTimeout = 20;
    @tracked strategies = [];

    constructor() {
        super(...arguments);
        this.getSettings.perform();
    }

    get selectedStrategy() {
        return this.strategies.find((strategy) => strategy.name === this.adhocStrategy);
    }

    get usesOfferTimeout() {
        return this.adhocStrategy === 'sequential';
    }

    @task *getSettings() {
        try {
            const settings = yield this.fetch.get('fleet-ops/settings/adhoc-dispatch-settings');
            this.adhocDistance = settings.adhoc_distance;
            this.adhocStrategy = settings.adhoc_strategy;
            this.adhocOfferTimeout = settings.adhoc_offer_timeout;
            this.strategies = settings.strategies ?? [];
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *saveSettings() {
        try {
            yield this.fetch.post('fleet-ops/settings/adhoc-dispatch-settings', {
                adhoc_distance: Number(this.adhocDistance),
                adhoc_strategy: this.adhocStrategy,
                adhoc_offer_timeout: Number(this.adhocOfferTimeout),
            });
            this.notifications.success(this.intl.t('settings.dispatch.saved'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
