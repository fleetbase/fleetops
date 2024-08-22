import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementFuelReportsIndexEditRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops update fuel-report')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.fuel-reports.index');
        }
    }

    model({ public_id }) {
        return this.store.queryRecord('fuel-report', { public_id, single: true, with: ['driver', 'vehicle', 'reporter'] });
    }
}
