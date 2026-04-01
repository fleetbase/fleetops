import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class MaintenancePartsIndexNewController extends Controller {
    @service partActions;
    @service hostRouter;
    @service intl;
    @service notifications;
    @service events;

    @tracked overlay;
    @tracked part = this.partActions.createNewInstance();

    @task *save(part) {
        try {
            yield part.save();
            this.events.trackResourceCreated(part);
            this.overlay?.close();
            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.maintenance.parts.index.details', part);
            this.notifications.success(this.intl.t('common.resource-created-success', { resource: this.intl.t('resource.part') }));
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.part = this.partActions.createNewInstance();
    }
}
