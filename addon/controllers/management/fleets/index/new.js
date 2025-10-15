import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ManagementFleetsIndexNewController extends Controller {
    @service fleetActions;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;
    @tracked fleet = this.fleetActions.createNewInstance();

    @task *save(fleet) {
        try {
            yield fleet.save();
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.management.fleets.index.details', fleet);
            this.notifications.success(
                this.intl.t('common.resource-created-success-name', {
                    resource: this.intl.t('resource.fleet'),
                    resourceName: fleet.name,
                })
            );
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.fleet = this.fleetActions.createNewInstance();
    }
}
