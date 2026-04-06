import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsSchedulerFleetScheduleRoute extends Route {
    @service store;
    @service notifications;
    @service abilities;
    @service intl;
    @service hostRouter;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops list driver')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.operations.scheduler.index');
        }
    }

    async model() {
        return this.store.query('driver', { limit: 200 });
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.drivers = model.toArray();
        controller.loadScheduleItems.perform();
        controller.loadScheduleExceptions.perform();
    }
}
