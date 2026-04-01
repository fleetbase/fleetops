import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class MaintenanceSchedulesIndexEditController extends Controller {
    @service maintenanceScheduleActions;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;

    get actionButtons() {
        return [{ icon: 'trash', fn: this.delete, permission: 'fleet-ops delete maintenance-schedule' }];
    }

    @task *save(schedule) {
        try {
            yield schedule.save();
            this.overlay?.close();
            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.maintenance.schedules.index.details', schedule);
            this.notifications.success(this.intl.t('common.resource-updated-success', { resource: this.intl.t('resource.maintenance-schedule') }));
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action cancel() {
        return this.hostRouter.transitionTo('console.fleet-ops.maintenance.schedules.index');
    }

    @action delete() {
        return this.maintenanceScheduleActions.delete(this.model, {
            onConfirm: () => {
                this.hostRouter.transitionTo('console.fleet-ops.maintenance.schedules.index');
            },
        });
    }
}
