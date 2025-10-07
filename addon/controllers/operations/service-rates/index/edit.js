import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class OperationsServiceRatesIndexEditController extends Controller {
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

    @task *save(serviceRate) {
        if (typeof serviceRate.syncServiceRateFees === 'function') {
            serviceRate.syncServiceRateFees();
        }
        if (typeof serviceRate.syncPerDropFees === 'function') {
            serviceRate.syncPerDropFees();
        }

        try {
            yield serviceRate.save();
            this.overlay?.close();

            yield this.hostRouter.transitionTo('console.fleet-ops.operations.service-rates.index.details', serviceRate);
            this.notifications.success(this.intl.t('fleet-ops.operations.service-rates.index.edit.success-message', { serviceName: serviceRate.service_name }));
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action cancel() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.operations.service-rates.index');
    }

    @action view() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.operations.service-rates.index.details', this.model);
    }

    #confirmContinueWithUnsavedChanges(serviceRate, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.management.places.index.edit.title'),
            body: this.intl.t('fleet-ops.management.places.index.edit.body'),
            acceptButtonText: this.intl.t('fleet-ops.management.places.index.edit.button'),
            confirm: async () => {
                serviceRate.rollbackAttributes();
                await this.hostRouter.transitionTo('console.fleet-ops.operations.service-rates.index.details', serviceRate);
            },
            ...options,
        });
    }
}
