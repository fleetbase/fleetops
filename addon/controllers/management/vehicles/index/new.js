import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

const DEFAULT_PROPERTIES = { status: 'active' };

export default class ManagementVehiclesIndexNewController extends Controller {
    @service store;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;
    @tracked vehicle = this.store.createRecord('vehicle', DEFAULT_PROPERTIES);

    @task *save(vehicle) {
        try {
            yield vehicle.save();
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.management.vehicles.index.details', vehicle);
            this.notifications.success(
                this.intl.t('common.resource-created-success-name', {
                    resource: this.intl.t('resource.vehicle'),
                    resourceName: vehicle.display_name,
                })
            );
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.vehicle = this.store.createRecord('vehicle', DEFAULT_PROPERTIES);
    }
}
