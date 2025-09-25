import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

const DEFAULT_PROPERTIES = { status: 'active' };

export default class ManagementDriversIndexNewController extends Controller {
    @service store;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;
    @tracked driver = this.store.createRecord('driver', DEFAULT_PROPERTIES);

    @task *save(driver) {
        try {
            yield driver.save();
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.management.drivers.index.details', driver);
            this.notifications.success(this.intl.t('fleet-ops.component.driver-form-panel.success-message', { driverName: driver.name }));
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.driver = this.store.createRecord('driver', DEFAULT_PROPERTIES);
    }
}
