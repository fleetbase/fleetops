import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ManagementFuelReportsIndexEditController extends Controller {
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

    @task *save(fuelReport) {
        try {
            yield fuelReport.save();
            this.overlay?.close();

            yield this.hostRouter.transitionTo('console.fleet-ops.management.fuel-reports.index.details', fuelReport);
            this.notifications.success(
                this.intl.t('common.resource-updated-success', {
                    resource: this.intl.t('resource.fuel-report'),
                    resourceName: fuelReport.public_id,
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

        return this.hostRouter.transitionTo('console.fleet-ops.management.fuel-reports.index');
    }

    @action view() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.management.fuel-reports.index.details', this.model);
    }

    #confirmContinueWithUnsavedChanges(fuelReport, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('common.continue-without-saving'),
            body: this.intl.t('common.continue-without-saving-prompt', { resource: this.intl.t('resource.fuel-report') }),
            acceptButtonText: this.intl.t('common.continue'),
            confirm: async () => {
                fuelReport.rollbackAttributes();
                await this.hostRouter.transitionTo('console.fleet-ops.management.fuel-reports.index.details', fuelReport);
            },
            ...options,
        });
    }
}
