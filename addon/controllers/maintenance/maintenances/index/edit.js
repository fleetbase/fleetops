import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class MaintenanceMaintenancesIndexEditController extends Controller {
    @service hostRouter;
    @service intl;
    @service notifications;
    @service modalsManager;
    @service events;

    @tracked overlay;

    get actionButtons() {
        return [
            {
                icon: 'eye',
                fn: this.view,
            },
        ];
    }

    @task *save(maintenance) {
        try {
            yield maintenance.save();
            this.events.trackResourceUpdated(maintenance);
            this.overlay?.close();
            yield this.hostRouter.transitionTo('console.fleet-ops.maintenance.maintenances.index.details', maintenance);
            this.notifications.success(
                this.intl.t('common.resource-updated-success', {
                    resource: this.intl.t('resource.maintenance'),
                    resourceName: maintenance.summary,
                })
            );
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action cancel() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }
        return this.hostRouter.transitionTo('console.fleet-ops.maintenance.maintenances.index');
    }

    @action view() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }
        return this.hostRouter.transitionTo('console.fleet-ops.maintenance.maintenances.index.details', this.model);
    }

    #confirmContinueWithUnsavedChanges(maintenance, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('common.continue-without-saving'),
            body: this.intl.t('common.continue-without-saving-prompt', { resource: this.intl.t('resource.maintenance') }),
            acceptButtonText: this.intl.t('common.continue'),
            confirm: async () => {
                maintenance.rollbackAttributes();
                await this.hostRouter.transitionTo('console.fleet-ops.maintenance.maintenances.index.details', maintenance);
            },
            ...options,
        });
    }
}
