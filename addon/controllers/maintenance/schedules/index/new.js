import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class MaintenanceSchedulesIndexNewController extends Controller {
    @service maintenanceScheduleActions;
    @service hostRouter;
    @service intl;
    @service notifications;
    @service events;
    @tracked overlay;
    @tracked schedule = this.maintenanceScheduleActions.createNewInstance();

    @task *save(schedule) {
        try {
            yield schedule.save();
            this.events.trackResourceCreated(schedule);
            this.overlay?.close();
            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.maintenance.schedules.index.details', schedule);
            this.notifications.success(this.intl.t('common.resource-created-success', { resource: this.intl.t('resource.maintenance-schedule') }));
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.schedule = this.maintenanceScheduleActions.createNewInstance();
    }
}
