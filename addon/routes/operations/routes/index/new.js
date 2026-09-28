import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsRoutesIndexNewRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops create route')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.operations.routes.index');
        }
    }

    queryParams = {
        selectedOrders: {
            refreshModel: false,
        },
    };
}
