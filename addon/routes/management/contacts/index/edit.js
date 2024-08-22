import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementContactsIndexEditRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops update contact')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.contacts.index');
        }
    }

    model({ public_id }) {
        return this.store.findRecord('contact', public_id);
    }
}
