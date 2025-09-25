import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

const DEFAULT_PROPERTIES = { status: 'active' };

export default class ManagementFleetsIndexNewController extends Controller {
    @service store;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;
    @tracked fleet = this.store.createRecord('fleet', DEFAULT_PROPERTIES);

    @task *save(fleet) {
        try {
            yield fleet.save();
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.management.fleets.index.details', fleet);
            this.notifications.success(this.intl.t('fleet-ops.component.fleet-form-panel.success-message', { fleetName: fleet.name }));
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.fleet = this.store.createRecord('fleet', DEFAULT_PROPERTIES);
    }
}
