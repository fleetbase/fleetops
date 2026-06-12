import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ConnectivityTelematicsEditController extends Controller {
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

    @task *save(telematic) {
        try {
            yield telematic.save();
            this.events.trackResourceUpdated(telematic);
            this.overlay?.close();

            yield this.hostRouter.transitionTo('console.fleet-ops.connectivity.telematics.details', telematic);
            this.notifications.success(
                this.intl.t('common.resource-updated-success', {
                    resource: this.intl.t('resource.telematic'),
                    resourceName: telematic.name ?? telematic.provider,
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

        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.telematics.index');
    }

    @action view() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.telematics.details', this.model);
    }

    @action openConnectionTestDialog() {
        this.modalsManager.show('modals/telematic-connection-diagnostics', {
            title: 'Test Connection',
            acceptButtonText: 'Run Test',
            acceptButtonIcon: 'plug',
            declineButtonText: 'Close',
            telematic: this.model,
            onTested: () => this.hostRouter.refresh(),
        });
    }

    #confirmContinueWithUnsavedChanges(telematic, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('common.continue-without-saving'),
            body: this.intl.t('common.continue-without-saving-prompt', { resource: this.intl.t('resource.telematic') }),
            acceptButtonText: this.intl.t('common.continue'),
            confirm: async () => {
                telematic.rollbackAttributes();
                await this.hostRouter.transitionTo('console.fleet-ops.connectivity.telematics.details', telematic);
            },
            ...options,
        });
    }
}
