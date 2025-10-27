import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ConnectivityDevicesIndexEditController extends Controller {
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

    @task *save(device) {
        try {
            yield device.save();
            this.overlay?.close();

            yield this.hostRouter.transitionTo('console.fleet-ops.connectivity.devices.index.details', device);
            this.notifications.success(
                this.intl.t('common.resource-updated-success', {
                    resource: this.intl.t('resource.device'),
                    resourceName: device.name ?? device.serial_number,
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

        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.devices.index');
    }

    @action view() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.devices.index.details', this.model);
    }

    #confirmContinueWithUnsavedChanges(device, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('common.continue-without-saving'),
            body: this.intl.t('common.continue-without-saving-prompt', { resource: this.intl.t('resource.device') }),
            acceptButtonText: this.intl.t('common.continue'),
            confirm: async () => {
                device.rollbackAttributes();
                await this.hostRouter.transitionTo('console.fleet-ops.connectivity.devices.index.details', device);
            },
            ...options,
        });
    }
}
