import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ManagementFleetsIndexEditController extends Controller {
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

    @task *save(fleet) {
        try {
            yield fleet.save();
            this.overlay?.close();

            yield this.hostRouter.transitionTo('console.fleet-ops.management.fleets.index.details', fleet);
            this.notifications.success(this.intl.t('fleet-ops.component.fleet-form-panel.success-message', { fleetName: fleet.name }));
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action cancel() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.management.fleets.index');
    }

    @action view() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.management.fleets.index.details', this.model);
    }

    #confirmContinueWithUnsavedChanges(fleet, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.management.fleets.index.edit.title'),
            body: this.intl.t('fleet-ops.management.fleets.index.edit.body'),
            acceptButtonText: this.intl.t('fleet-ops.management.fleets.index.edit.button'),
            confirm: async () => {
                fleet.rollbackAttributes();
                await this.hostRouter.transitionTo('console.fleet-ops.management.fleets.index.details', fleet);
            },
            ...options,
        });
    }
}
