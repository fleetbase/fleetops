import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class MaintenanceInspectionSubmissionsIndexNewRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops create inspection-submission')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-submissions.index');
        }
    }
}
