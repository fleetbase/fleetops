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
    @service store;
    @tracked adhocDistance = 6000;
    @tracked adhocStrategy = 'sequential';
    @tracked adhocOfferTimeout = 90;
    @tracked strategies = [];
    @tracked orderConfigs = [];

    constructor() {
        super(...arguments);
        this.getSettings.perform();
        this.getOrderConfigs.perform();
    }

    get timeoutInvalid() {
        const value = Number(this.adhocOfferTimeout);
        return this.adhocOfferTimeout !== '' && this.adhocOfferTimeout !== null && (Number.isNaN(value) || value < 10 || value > 600);
    }

    /**
     * The order configs that offer customer orders to nearby drivers, and whether each follows
     * these defaults or sets its own.
     */
    get adhocConfigs() {
        return this.orderConfigs
            .map((config) => {
                const adhoc = config.policies?.dispatch?.adhoc ?? {};
                if (!adhoc.customer_orders) {
                    return null;
                }
                const own = ['strategy', 'offer_timeout', 'radius'].filter((key) => adhoc[key] !== undefined && adhoc[key] !== null && adhoc[key] !== '');
                return { id: config.id, name: config.name, own: own.length > 0, ownSettings: own.map((key) => this.intl.t(`settings.dispatch.setting-${key}`)).join(', ') };
            })
            .filter(Boolean);
    }

    get overrideCount() {
        return this.adhocConfigs.filter((config) => config.own).length;
    }

    @task *getOrderConfigs() {
        try {
            this.orderConfigs = yield this.store.findAll('order-config').then(Array.from);
        } catch {
            this.orderConfigs = [];
        }
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
        if (this.timeoutInvalid) {
            return;
        }
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
