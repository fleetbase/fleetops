import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class OperationsServiceRatesIndexNewController extends Controller {
    @service serviceRateActions;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;
    @tracked serviceRate = this.serviceRateActions.createNewInstance();

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

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.operations.service-rates.index.details', serviceRate);
            this.notifications.success(
                this.intl.t('common.resource-created-success-name', {
                    resource: this.intl.t('resource.service-rate'),
                    resourceName: serviceRate.service_name,
                })
            );
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.serviceRate = this.serviceRateActions.createNewInstance();
    }
}
