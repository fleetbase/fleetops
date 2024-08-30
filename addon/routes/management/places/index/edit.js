import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementPlacesIndexEditRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops update place')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.places.index');
        }
    }

    model({ public_id }) {
        return this.store.findRecord('place', public_id);
    }
}
