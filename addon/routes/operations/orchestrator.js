import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * Operations::AllocationRoute
 *
 * Entry point for the Dispatcher Workbench at /operations/allocation.
 * The route performs an ability check and then hands off to the
 * OrchestratorWorkbench component via the controller.
 */
export default class OperationsAllocationRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops list order')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops');
        }
    }
}
