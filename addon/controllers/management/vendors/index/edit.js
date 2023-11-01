import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ManagementVendorsIndexEditController extends Controller {
    /**
     * Inject the `hostRouter` service
     *
     * @memberof ManagementVehiclesIndexEditController
     */
    @service hostRouter;

    /**
     * Inject the `hostRouter` service
     *
     * @memberof ManagementVehiclesIndexEditController
     */
    @service modalsManager;

    /**
     * The overlay component context.
     *
     * @memberof ManagementVehiclesIndexEditController
     */
    @tracked overlay;

    /**
     * When exiting the overlay.
     *
     * @return {Transition}
     * @memberof ManagementVehiclesIndexEditController
     */
    @action transitionBack(vendor) {
        // check if vendor record has been edited and prompt for confirmation
        if (vendor.hasDirtyAttributes) {
            return this.confirmContinueWithUnsavedChanges(vendor, {
                confirm: () => {
                    vendor.rollbackAttributes();
                    return this.transitionToRoute('management.vendors.index');
                },
            });
        }

        return this.transitionToRoute('management.vendors.index');
    }

    /**
     * Set the overlay component context object.
     *
     * @param {OverlayContext} overlay
     * @memberof ManagementVehiclesIndexEditController
     */
    @action setOverlayContext(overlay) {
        this.overlay = overlay;
    }

    /**
     * When vendor details button is clicked in overlay.
     *
     * @param {VehicleModel} vendor
     * @return {Promise}
     * @memberof ManagementVehiclesIndexEditController
     */
    @action onViewDetails(vendor) {
        // check if vendor record has been edited and prompt for confirmation
        if (vendor.hasDirtyAttributes) {
            return this.confirmContinueWithUnsavedChanges(vendor);
        }

        return this.transitionToRoute('management.vendors.index.details', vendor);
    }

    /**
     * Trigger a route refresh and focus the new vendor created.
     *
     * @param {VehicleModel} vendor
     * @return {Promise}
     * @memberof ManagementVehiclesIndexEditController
     */
    @action onAfterSave(vendor) {
        if (this.overlay) {
            this.overlay.close();
        }

        this.hostRouter.refresh();
        return this.transitionToRoute('management.vendors.index.details', vendor);
    }

    /**
     * Prompts the user to confirm if they wish to continue with unsaved changes.
     *
     * @method
     * @param {VehicleModel} vendor - The vendor object with unsaved changes.
     * @param {Object} [options={}] - Additional options for configuring the modal.
     * @returns {Promise} A promise that resolves when the user confirms, and transitions to a new route.
     * @memberof ManagementVehiclesIndexEditController
     */
    confirmContinueWithUnsavedChanges(vendor, options = {}) {
        return this.modalsManager.confirm({
            title: 'Continue Without Saving?',
            body: 'Unsaved changes to this vendor will be lost. Click continue to proceed.',
            acceptButtonText: 'Continue without saving',
            confirm: () => {
                vendor.rollbackAttributes();
                return this.transitionToRoute('management.vendors.index.details', vendor);
            },
            ...options,
        });
    }
}
