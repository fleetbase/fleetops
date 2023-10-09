import Controller, { inject as controller } from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class ManagementFleetsIndexNewController extends Controller {
    /**
     * Inject the `management.fleets.index` controller
     *
     * @var {Controller}
     */
    @controller('management.fleets.index') index;

    /**
     * Inject the `currentUser` service
     *
     * @var {Service}
     */
    @service store;

    /**
     * Inject the `hostRouter` service
     *
     * @var {Service}
     */
    @service hostRouter;

    /**
     * Inject the `notifications` service
     *
     * @var {Service}
     */
    @service notifications;

    /**
     * Inject the `loader` service
     *
     * @var {Service}
     */
    @service loader;

    /**
     * The fleet being created.
     *
     * @var {FleetModel}
     */
    @tracked fleet = this.store.createRecord('fleet', { status: 'active' });

    /**
     * True if creating fleet.
     *
     * @var {Boolean}
     */
    @tracked isCreatingFleet = false;

    /**
     * Saves the fleet to server
     *
     * @void
     */
    @action createFleet() {
        const { fleet } = this;

        this.isCreatingFleet = true;
        this.loader.showLoader('.overlay-inner-content', 'Creating fleet...');

        try {
            return fleet
                .save()
                .then((fleet) => {
                    return this.transitionToRoute('management.fleets.index').then(() => {
                        this.notifications.success(`New fleet (${fleet.name}) created.`);
                        this.resetForm();
                        this.hostRouter.refresh();
                    });
                })
                .catch((error) => {
                    console.log(error);
                    this.notifications.serverError(error);
                })
                .finally(() => {
                    this.isCreatingFleet = false;
                    this.loader.removeLoader();
                });
        } catch (error) {
            this.isCreatingFleet = false;
            this.loader.removeLoader();
        }
    }

    /**
     * Resets the driver form
     *
     * @void
     */
    @action resetForm() {
        this.fleet = this.store.createRecord('fleet');
    }

    @action transitionBack() {
        return this.transitionToRoute('management.fleets.index');
    }
}
