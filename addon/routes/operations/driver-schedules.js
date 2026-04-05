import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsDriverSchedulesRoute extends Route {
    @service abilities;
    @service notifications;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops list driver')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.transitionTo('console.fleet-ops.operations.orders');
        }
    }
}
