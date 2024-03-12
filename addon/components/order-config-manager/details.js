import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

/**
 * Component class for managing the details of an order configuration.
 * This component allows for saving and deleting order configurations,
 * with user feedback through notifications and confirmation modals.
 *
 * @extends Component
 */
export default class OrderConfigManagerDetailsComponent extends Component {
    /**
     * Modals manager service for handling modal dialogs.
     * @service
     */
    @service modalsManager;

    /**
     * Notifications service for displaying user feedback.
     * @service
     */
    @service notifications;

    /**
     * Internationalization service for handling translations.
     * @service
     */
    @service intl;

    /**
     * Store service for handling data storage and retrieval.
     * @service
     */
    @service store;

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
    constructor(owner, { config }) {
        super(...arguments);
        this.config = config;
    }

    /**
     * Action method to save the current configuration.
     * Provides user feedback and executes callback on successful update.
     * @action
     */
    @action save() {
        return this.config
            .save()
            .then(() => {
                this.notifications.success(this.intl.t('fleet-ops.component.order-config-manager.saved-success-message', { orderConfigName: this.config.name }));
                if (typeof this.args.onConfigUpdated === 'function') {
                    this.args.onConfigUpdated(this.config);
                }
            })
            .catch((error) => {
                this.notifications.serverError(error);
            });
    }

    /**
     * Action method to delete the current configuration.
     * Provides a confirmation modal and executes callback on successful deletion.
     * @action
     */
    @action delete() {
        this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.component.order-config-manager.details.delete.delete-title'),
            body: this.intl.t('fleet-ops.component.order-config-manager.details.delete.delete-body-message'),
            acceptButtonText: this.intl.t('fleet-ops.component.order-config-manager.details.delete.confirm-delete'),
            confirm: (modal) => {
                if (typeof this.args.onConfigDeleting === 'function') {
                    this.args.onConfigDeleting(this.config);
                }

                modal.startLoading();
                return this.config
                    .destroyRecord()
                    .then(() => {
                        if (typeof this.args.onConfigDeleted === 'function') {
                            this.args.onConfigDeleted(this.config);
                        }
                    })
                    .catch((error) => {
                        this.notifications.serverError(error);
                    })
                    .finally(() => {
                        modal.done();
                    });
            },
        });
    }
}
