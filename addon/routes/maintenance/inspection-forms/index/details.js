import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class MaintenanceInspectionFormsIndexDetailsRoute extends Route {
    @service store;
    @service hostRouter;
    @service notifications;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops view inspection-form')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-forms.index');
        }
    }

    model({ public_id }) {
        return this.store.findRecord('inspection-form', public_id);
    }

    @action error(error) {
        this.notifications.serverError(error);
        return this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-forms.index');
    }
}
