import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementFuelReportsIndexDetailsRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops view fuel-report')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.fuel-reports.index');
        }
    }

    queryParams = {
        view: { refreshModel: false },
    };

    model({ public_id }) {
        return this.store.queryRecord('fuel-report', { public_id, single: true, with: ['driver', 'vehicle', 'reporter'] });
    }
}
