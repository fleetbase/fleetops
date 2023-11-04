import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class ManagementContactsIndexEditController extends Controller {
    /**
     * Inject the `hostRouter` service
     *
     * @memberof ManagementcontactsIndexEditController
     */
    @service hostRouter;

    /**
     * Inject the `hostRouter` service
     *
     * @memberof ManagementcontactsIndexEditController
     */
    @service modalsManager;

    /**
     * The overlay component context.
     *
     * @memberof ManagementcontactsIndexEditController
     */
    @tracked overlay;

    /**
     * When exiting the overlay.
     *
     * @return {Transition}
     * @memberof ManagementcontactsIndexEditController
     */
    @action transitionBack(contact) {
        // check if contact record has been edited and prompt for confirmation
        if (contact.hasDirtyAttributes) {
            return this.confirmContinueWithUnsavedChanges(contact, {
                confirm: () => {
                    contact.rollbackAttributes();
                    return this.transitionToRoute('management.contacts.index');
                },
            });
        }

        return this.transitionToRoute('management.contacts.index');
    }

    /**
     * Set the overlay component context object.
     *
     * @param {OverlayContext} overlay
     * @memberof ManagementcontactsIndexEditController
     */
    @action setOverlayContext(overlay) {
        this.overlay = overlay;
    }

    /**
     * When contact details button is clicked in overlay.
     *
     * @param {ContactModel} contact
     * @return {Promise}
     * @memberof ManagementcontactsIndexEditController
     */
    @action onViewDetails(contact) {
        // check if contact record has been edited and prompt for confirmation
        if (contact.hasDirtyAttributes) {
            return this.confirmContinueWithUnsavedChanges(contact);
        }

        return this.transitionToRoute('management.contacts.index.details', contact);
    }

    /**
     * Trigger a route refresh and focus the new contact created.
     *
     * @param {ContactModel} contact
     * @return {Promise}
     * @memberof ManagementcontactsIndexEditController
     */
    @action onAfterSave(contact) {
        if (this.overlay) {
            this.overlay.close();
        }

        this.hostRouter.refresh();
        return this.transitionToRoute('management.contacts.index.details', contact);
    }

    /**
     * Prompts the user to confirm if they wish to continue with unsaved changes.
     *
     * @method
     * @param {ContactModel} contact - The contact object with unsaved changes.
     * @param {Object} [options={}] - Additional options for configuring the modal.
     * @returns {Promise} A promise that resolves when the user confirms, and transitions to a new route.
     * @memberof ManagementcontactsIndexEditController
     */
    confirmContinueWithUnsavedChanges(contact, options = {}) {
        return this.modalsManager.confirm({
            title: 'Continue Without Saving?',
            body: 'Unsaved changes to this contact will be lost. Click continue to proceed.',
            acceptButtonText: 'Continue without saving',
            confirm: () => {
                contact.rollbackAttributes();
                return this.transitionToRoute('management.contacts.index.details', contact);
            },
            ...options,
        });
    }
}
