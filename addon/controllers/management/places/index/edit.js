import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ManagementPlacesIndexEditController extends Controller {
    @service hostRouter;
    @service intl;
    @service notifications;
    @service modalsManager;
    @tracked overlay;
    @tracked actionButtons = [
        {
            icon: 'eye',
            fn: this.view,
        },
    ];

    @task *save(place) {
        try {
            yield place.save();
            this.overlay?.close();

            yield this.hostRouter.transitionTo('console.fleet-ops.management.places.index.details', place);
            this.notifications.success(this.intl.t('fleet-ops.component.place-form-panel.success-message', { placeAddress: place.address }));
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action cancel() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.management.places.index');
    }

    @action view() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.management.places.index.details', this.model);
    }

    #confirmContinueWithUnsavedChanges(place, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.management.places.index.edit.title'),
            body: this.intl.t('fleet-ops.management.places.index.edit.body'),
            acceptButtonText: this.intl.t('fleet-ops.management.places.index.edit.button'),
            confirm: async () => {
                place.rollbackAttributes();
                await this.hostRouter.transitionTo('console.fleet-ops.management.places.index.details', place);
            },
            ...options,
        });
    }
}
