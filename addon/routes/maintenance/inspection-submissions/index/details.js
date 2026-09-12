import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class MaintenanceInspectionSubmissionsIndexDetailsRoute extends Route {
    @service store;
    @service hostRouter;
    @service notifications;

    model({ public_id }) {
        return this.store.findRecord('inspection-submission', public_id);
    }

    @action error(error) {
        this.notifications.serverError(error);
        return this.hostRouter.transitionTo('maintenance.inspection-submissions.index');
    }
}
