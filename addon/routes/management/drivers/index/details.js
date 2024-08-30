import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementDriversIndexDetailsRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops view driver')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.drivers.index');
        }
    }

    queryParams = {
        view: { refreshModel: false },
    };

    model({ public_id }) {
        return this.store.findRecord('driver', public_id);
    }
}
