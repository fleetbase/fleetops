import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ManagementDriversIndexNewController extends Controller {
    @service driverActions;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;
    @tracked driver = this.driverActions.createNewInstance();

    @task *save(driver) {
        try {
            yield driver.save();
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.management.drivers.index.details', driver);
            this.notifications.success(
                this.intl.t('common.resource-created-success-name', {
                    resource: this.intl.t('resource.driver'),
                    resourceName: driver.name,
                })
            );
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.driver = this.driverActions.createNewInstance();
    }
}
