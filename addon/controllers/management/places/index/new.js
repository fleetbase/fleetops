import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

const DEFAULT_PROPERTIES = {};

export default class ManagementPlacesIndexNewController extends Controller {
    @service store;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;
    @tracked place = this.store.createRecord('place', DEFAULT_PROPERTIES);

    @task *save(place) {
        try {
            yield place.save();
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.management.places.index.details', place);
            this.notifications.success(this.intl.t('fleet-ops.component.place-form-panel.success-message', { placeAddress: place.address }));
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.place = this.store.createRecord('place', DEFAULT_PROPERTIES);
    }
}
