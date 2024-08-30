import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementVehiclesIndexDetailsRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops view vehicle')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.vehicles.index');
        }
    }

    queryParams = {
        view: { refreshModel: false },
    };

    model(params) {
        return this.store.findRecord('vehicle', params.public_id);
    }

    afterModel(model) {
        model.loadDriver();
    }
}
