import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class MaintenanceMaintenancesIndexNewController extends Controller {
    @service maintenanceActions;
    @service hostRouter;
    @service intl;
    @service notifications;
    @service events;

    @tracked overlay;
    @tracked maintenance = this.maintenanceActions.createNewInstance();

    @task *save(maintenance) {
        try {
            yield maintenance.save();
            this.events.trackResourceCreated(maintenance);
            this.overlay?.close();
            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.maintenance.maintenances.index.details', maintenance);
            this.notifications.success(this.intl.t('common.resource-created-success', { resource: this.intl.t('resource.maintenance') }));
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.maintenance = this.maintenanceActions.createNewInstance();
    }
}
