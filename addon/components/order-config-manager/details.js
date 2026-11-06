import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * Component class for managing the details of an order configuration.
 * This component allows for saving and deleting order configurations,
 * with user feedback through notifications and confirmation modals.
 *
 * @extends Component
 */
export default class OrderConfigManagerDetailsComponent extends Component {
    @service modalsManager;
    @service notifications;
    @service intl;
    @service store;
    @service fetch;

    /**
     * The adhoc dispatch strategies the server offers.
     * @tracked
     */
    @tracked strategies = [];

    /**
     * Tracked property for the configuration being managed.
     * @tracked
     */
    @tracked config;

    /**
     * Constructor for OrderConfigManagerDetailsComponent.
     * Initializes the component with the provided configuration.
     * @param {Object} owner - The owner of the component.
     * @param {Object} args - The arguments passed to the component, including the configuration.
     */
    constructor(owner, { config, configManagerContext }) {
        super(...arguments);
        this.config = config;
        this.loadStrategies.perform();

        configManagerContext.on('onConfigChanged', (newConfig) => {
            this.changeConfig(newConfig);
        });
    }

    /**
     * The strategy choices, with the company default first. An empty name stands for no strategy of the config's own.
     */
    get strategyOptions() {
        return [{ name: '', label: this.intl.t('order-config-manager.details.company-default') }, ...this.strategies];
    }

    get adhocStrategy() {
        return this.config?.adhoc_strategy ?? '';
    }

    @action setAdhocStrategy(name) {
        this.config.adhoc_strategy = name || null;
    }

    @task *loadStrategies() {
        try {
            const { strategies } = yield this.fetch.get('fleet-ops/settings/adhoc-dispatch-strategies');
            this.strategies = strategies ?? [];
        } catch {
            this.strategies = [];
        }
    }

    /**
     * Task method to save the current configuration.
     * Provides user feedback and executes callback on successful update.
     * @action
     */
    @task *save() {
        try {
            yield this.config.save();
            this.notifications.success(this.intl.t('order-config-manager.saved-success-message', { orderConfigName: this.config.name }));
            if (typeof this.args.onConfigUpdated === 'function') {
                this.args.onConfigUpdated(this.config);
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Action method to delete the current configuration.
     * Provides a confirmation modal and executes callback on successful deletion.
     * @action
     */
    @action delete() {
        this.modalsManager.confirm({
            title: this.intl.t('order-config-manager.details.delete.delete-title'),
            body: this.intl.t('order-config-manager.details.delete.delete-body-message'),
            acceptButtonText: this.intl.t('order-config-manager.details.delete.confirm-delete'),
            confirm: async (modal) => {
                if (typeof this.args.onConfigDeleting === 'function') {
                    this.args.onConfigDeleting(this.config);
                }

                modal.startLoading();

                try {
                    await this.deleteConfig.perform();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    /**
     * Task to delete current order config.
     *
     * @memberof OrderConfigManagerDetailsComponent
     */
    @task *deleteConfig() {
        try {
            yield this.config.destroyRecord();
            this.notifications.success(`Order config ${this.config.name} was deleted.`);

            if (typeof this.args.onConfigDeleted === 'function') {
                this.args.onConfigDeleted();
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Handle change of config.
     *
     * @param {OrderConfigModel} newConfig
     * @memberof OrderConfigManagerDetailsComponent
     */
    changeConfig(newConfig) {
        this.config = newConfig;
    }
}
