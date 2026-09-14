import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class MaintenanceInspectionFormsIndexEditRoute extends Route {
    @service store;
    @service hostRouter;
    @service notifications;

    model({ public_id }) {
        return this.store.findRecord('inspection-form', public_id);
    }

    @action error(error) {
        this.notifications.serverError(error);
        return this.hostRouter.transitionTo('maintenance.inspection-forms.index');
    }
}
