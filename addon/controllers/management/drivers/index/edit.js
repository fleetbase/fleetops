import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class ManagementDriversIndexEditController extends Controller {
    /**
     * True if updating service rate.
     *
     * @var {Boolean}
     */
    @tracked isUpdatingDriver = false;
    /**
     * Inject the `loader` service
     *
     * @var {Service}
     */
    @service loader;
    /**
     * Inject the `notifications` service
     *
     * @var {Service}
     */
    @service notifications;
    /**
     * Inject the `hostRouter` service
     *
     * @var {Service}
     */
    @service hostRouter;
    /**
     * Inject the `currentUser` service
     *
     * @var {Service}
     */
    @service store;
    /**
     * Updates the drivers to server
     *
     * @void
     */
    @action updateDriver() {
        const { driver } = this;

        this.isUpdatingDriver = true;
        this.loader.showLoader('.overlay-inner-content', 'Updating driver...');

        try {
            return driver
                .save()
                .then((driver) => {
                    return this.transitionToRoute('management.drivers.index').then(() => {
                        this.notifications.success(`Driver '${driver.name}' updated`);
                        this.resetForm();
                        this.hostRouter.refresh();
                    });
                })
                .catch(this.notifications.serverError)
                .finally(() => {
                    this.isUpdatingDriver = false;
                    this.loader.removeLoader();
                });
        } catch (error) {
            this.isUpdatingDriver = false;
            this.loader.removeLoader();
        }
    }

    @action transitionBack() {
        return this.transitionToRoute('management.drivers.index');
    }
    /**
     * Resets the driver form
     *
     * @void
     */
    @action resetForm() {
        this.driver = this.store.createRecord('driver');
    }
}
