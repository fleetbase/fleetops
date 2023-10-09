import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class ManagementFleetsIndexEditController extends Controller {
    /**
     * True if updating service rate.
     *
     * @var {Boolean}
     */
    @tracked isUpdatingFleet = false;

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
     * Updates the fleets to server
     *
     * @void
     */
    @action updateFleet() {
        const { model } = this;

        console.log(model, model);
        this.isUpdatingFleet = true;
        this.loader.showLoader('.overlay-inner-content', 'Updating fleet...');

        try {
            return model
                .save()
                .then((model) => {
                    return this.transitionToRoute('management.fleets.index').then(() => {
                        this.notifications.success(`Fleet '${model.name}' updated`);
                        this.hostRouter.refresh();
                    });
                })
                .catch(this.notifications.serverError)
                .finally(() => {
                    this.isUpdatingFleet = false;
                    this.loader.removeLoader();
                });
        } catch (error) {
            this.isUpdatingFleet = false;
            this.loader.removeLoader();
        }
    }

    @action transitionBack() {
        return this.transitionToRoute('management.fleets.index');
    }
}
