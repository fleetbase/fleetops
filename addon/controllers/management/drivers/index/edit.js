import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ManagementDriversIndexEditController extends Controller {
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

    @task *save(driver) {
        try {
            yield driver.save();
            this.overlay?.close();

            yield this.hostRouter.transitionTo('console.fleet-ops.management.drivers.index.details', driver);
            this.notifications.success(
                this.intl.t('common.resource-updated-success', {
                    resource: this.intl.t('resource.driver'),
                    resourceName: driver.name,
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

        return this.hostRouter.transitionTo('console.fleet-ops.management.drivers.index');
    }

    @action view() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.management.drivers.index.details', this.model);
    }

    #confirmContinueWithUnsavedChanges(driver, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('common.continue-without-saving'),
            body: this.intl.t('common.continue-without-saving-prompt', { resource: this.intl.t('resource.driver') }),
            acceptButtonText: this.intl.t('common.continue'),
            confirm: async () => {
                driver.rollbackAttributes();
                await this.hostRouter.transitionTo('console.fleet-ops.management.drivers.index.details', driver);
            },
            ...options,
        });
    }
}
