import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
export default class ManagementTrailersIndexNewController extends Controller {
    @service store;
    @service hostRouter;
    @service intl;
    @service notifications;
    @service events;
    @tracked overlay;
    @tracked trailer = this.store.createRecord('trailer', { status: 'available', asset_class: 'trailer', measurement_system: 'metric' });
    @task *save(trailer) {
        try {
            yield trailer.save();
            this.events.trackResourceCreated(trailer);
            this.overlay?.close();
            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.management.trailers.index.details', trailer);
            this.notifications.success(this.intl.t('trailer.messages.created'));
            this.resetForm();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
    @action resetForm() {
        this.trailer = this.store.createRecord('trailer', { status: 'available', asset_class: 'trailer', measurement_system: 'metric' });
    }
}
