import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ConnectivitySensorsIndexEditController extends Controller {
    @service hostRouter;
    @service intl;
    @service notifications;
    @service modalsManager;
    @tracked overlay;

    get actionButtons() {
        return [
            {
                icon: 'eye',
                fn: this.view,
            },
        ];
    }

    @task *save(sensor) {
        try {
            yield sensor.save();
            this.overlay?.close();

            yield this.hostRouter.transitionTo('console.fleet-ops.connectivity.sensors.index.details', sensor);
            this.notifications.success(
                this.intl.t('common.resource-updated-success', {
                    resource: this.intl.t('resource.sensor'),
                    resourceName: sensor.name ?? sensor.serial_number,
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

        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.sensors.index');
    }

    @action view() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.sensors.index.details', this.model);
    }

    #confirmContinueWithUnsavedChanges(sensor, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('common.continue-without-saving'),
            body: this.intl.t('common.continue-without-saving-prompt', { resource: this.intl.t('resource.sensor') }),
            acceptButtonText: this.intl.t('common.continue'),
            confirm: async () => {
                sensor.rollbackAttributes();
                await this.hostRouter.transitionTo('console.fleet-ops.connectivity.sensors.index.details', sensor);
            },
            ...options,
        });
    }
}
