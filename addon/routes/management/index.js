import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementIndexRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops list driver')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops');
        }
    }

    queryParams = {
        view: { refreshModel: false },
        status: { refreshModel: false },
        filters: { refreshModel: false },
        fleet: { refreshModel: false },
        q: { refreshModel: false },
        saved: { refreshModel: false },
        assigned: { refreshModel: false },
        window: { refreshModel: false },
    };

    setupController(controller, model, transition) {
        super.setupController(controller, model, transition);
        controller.reload.perform();
    }
}
