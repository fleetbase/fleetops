import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ManagementTrailersIndexEditController extends Controller {
    @service hostRouter;
    @service intl;
    @service notifications;
    @service modalsManager;
    @service events;
    @tracked overlay;
    @tracked actionButtons = [
        {
            icon: 'eye',
            fn: this.view,
        },
    ];

    @task *save(trailer) {
        try {
            yield trailer.save();
            this.events.trackResourceUpdated(trailer);
            this.overlay?.close();

            yield this.hostRouter.transitionTo('console.fleet-ops.management.trailers.index.details', trailer);
            this.notifications.success(
                this.intl.t('common.resource-updated-success', {
                    resource: this.intl.t('resource.trailer'),
                    resourceName: trailer.displayName,
                })
            );
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action cancel() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.management.trailers.index');
    }

    @action view() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.management.trailers.index.details', this.model);
    }

    #confirmContinueWithUnsavedChanges(trailer, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('common.continue-without-saving'),
            body: this.intl.t('common.continue-without-saving-prompt', { resource: this.intl.t('resource.trailer') }),
            acceptButtonText: this.intl.t('common.continue'),
            confirm: async () => {
                trailer.rollbackAttributes();
                await this.hostRouter.transitionTo('console.fleet-ops.management.trailers.index.details', trailer);
            },
            ...options,
        });
    }
}
