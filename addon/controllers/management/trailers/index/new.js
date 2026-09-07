import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

const DEFAULT_PROPERTIES = { status: 'available', asset_class: 'trailer', measurement_system: 'metric' };

export default class ManagementTrailersIndexNewController extends Controller {
    @service store;
    @service hostRouter;
    @service intl;
    @service notifications;
    @service events;
    @tracked overlay;
    @tracked trailer = this.store.createRecord('trailer', DEFAULT_PROPERTIES);

    @task *save(trailer) {
        try {
            yield trailer.save();
            this.events.trackResourceCreated(trailer);
            this.overlay?.close();

            // Transition with the record so the details route renders immediately from the
            // create response; refreshing afterwards reloads the index behind the panel.
            yield this.hostRouter.transitionTo('console.fleet-ops.management.trailers.index.details', trailer);
            yield this.hostRouter.refresh();
            this.notifications.success(
                this.intl.t('common.resource-created-success-name', {
                    resource: this.intl.t('resource.trailer'),
                    resourceName: trailer.displayName,
                })
            );
            this.resetForm();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action resetForm() {
        this.trailer = this.store.createRecord('trailer', DEFAULT_PROPERTIES);
    }
}
