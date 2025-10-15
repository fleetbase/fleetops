import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ManagementPlacesIndexNewController extends Controller {
    @service placeActions;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;
    @tracked place = this.placeActions.createNewInstance();

    @task *save(place) {
        try {
            yield place.save();
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.management.places.index.details', place);
            this.notifications.success(
                this.intl.t('common.resource-created-success-name', {
                    resource: this.intl.t('resource.place'),
                    resourceName: place.address,
                })
            );
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.place = this.placeActions.createNewInstance();
    }
}
