import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class ManagementDriversIndexEditController extends Controller {
    /**
     * Inject the `hostRouter` service
     *
     * @memberof ManagementDriversIndexEditController
     */
    @service hostRouter;

    /**
     * Inject the `hostRouter` service
     *
     * @memberof ManagementDriversIndexEditController
     */
    @service modalsManager;

    /**
     * The overlay component context.
     *
     * @memberof ManagementDriversIndexEditController
     */
    @tracked overlay;

    /**
     * When exiting the overlay.
     *
     * @return {Transition}
     * @memberof ManagementDriversIndexEditController
     */
    @action transitionBack(driver) {
        // check if driver record has been edited and prompt for confirmation
        if (driver.hasDirtyAttributes) {
            return this.confirmContinueWithUnsavedChanges(driver, {
                confirm: () => {
                    driver.rollbackAttributes();
                    return this.transitionToRoute('management.drivers.index');
                },
            });
        }

        return this.transitionToRoute('management.drivers.index');
    }

    /**
     * Set the overlay component context object.
     *
     * @param {OverlayContext} overlay
     * @memberof ManagementDriversIndexEditController
     */
    @action setOverlayContext(overlay) {
        this.overlay = overlay;
    }

    /**
     * When driver details button is clicked in overlay.
     *
     * @param {VehicleModel} driver
     * @return {Promise}
     * @memberof ManagementDriversIndexEditController
     */
    @action onViewDetails(driver) {
        // check if driver record has been edited and prompt for confirmation
        if (driver.hasDirtyAttributes) {
            return this.confirmContinueWithUnsavedChanges(driver);
        }

        return this.transitionToRoute('management.drivers.index.details', driver);
    }

    /**
     * Trigger a route refresh and focus the new driver created.
     *
     * @param {VehicleModel} driver
     * @return {Promise}
     * @memberof ManagementDriversIndexEditController
     */
    @action onAfterSave(driver) {
        if (this.overlay) {
            this.overlay.close();
        }

        this.hostRouter.refresh();
        return this.transitionToRoute('management.drivers.index.details', driver);
    }

    /**
     * Prompts the user to confirm if they wish to continue with unsaved changes.
     *
     * @method
     * @param {VehicleModel} driver - The driver object with unsaved changes.
     * @param {Object} [options={}] - Additional options for configuring the modal.
     * @returns {Promise} A promise that resolves when the user confirms, and transitions to a new route.
     * @memberof ManagementDriversIndexEditController
     */
    confirmContinueWithUnsavedChanges(driver, options = {}) {
        return this.modalsManager.confirm({
            title: 'Continue Without Saving?',
            body: 'Unsaved changes to this driver will be lost. Click continue to proceed.',
            acceptButtonText: 'Continue without saving',
            confirm: () => {
                driver.rollbackAttributes();
                return this.transitionToRoute('management.drivers.index.details', driver);
            },
            ...options,
        });
    }
}
