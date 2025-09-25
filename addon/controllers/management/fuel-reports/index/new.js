import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ManagementFuelReportsIndexNewController extends Controller {
    @service store;
    @service currentUser;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;
    @tracked fuelReport = this.store.createRecord('fuelReport', { reporter: this.currentUser.user });

    @task *save(fuelReport) {
        try {
            yield fuelReport.save();
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.management.fuel-reports.index.details', fuelReport);
            this.notifications.success(this.intl.t('fleet-ops.component.fuel-report-form-panel.success-message'));
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.fuelReport = this.store.createRecord('fuelReport', { reporter: this.currentUser.user });
    }
}
