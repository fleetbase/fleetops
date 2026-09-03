import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
export default class ManagementTrailersIndexEditRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;
    @action error(error) {
        this.notifications.serverError(error);
        return this.hostRouter.transitionTo('console.fleet-ops.management.trailers.index');
    }
    beforeModel() {
        if (this.abilities.cannot('fleet-ops update trailer')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.management.trailers.index');
        }
    }
    model({ public_id }) {
        return this.store.findRecord('trailer', public_id);
    }
}
