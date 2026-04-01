import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class MaintenanceEquipmentIndexNewController extends Controller {
    @service equipmentActions;
    @service hostRouter;
    @service intl;
    @service notifications;
    @service events;

    @tracked overlay;
    @tracked equipment = this.equipmentActions.createNewInstance();

    @task *save(equipment) {
        try {
            yield equipment.save();
            this.events.trackResourceCreated(equipment);
            this.overlay?.close();
            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.maintenance.equipment.index.details', equipment);
            this.notifications.success(this.intl.t('common.resource-created-success', { resource: this.intl.t('resource.equipment') }));
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.equipment = this.equipmentActions.createNewInstance();
    }
}
