import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ManagementContactsIndexEditController extends Controller {
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

    @task *save(contact) {
        try {
            yield contact.save();
            this.overlay?.close();

            yield this.hostRouter.transitionTo('console.fleet-ops.management.contacts.index.details', contact);
            this.notifications.success(
                this.intl.t('common.resource-updated-success', {
                    resource: this.intl.t('resource.contact'),
                    resourceName: contact.name,
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

        return this.hostRouter.transitionTo('console.fleet-ops.management.contacts.index');
    }

    @action view() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.management.contacts.index.details', this.model);
    }

    #confirmContinueWithUnsavedChanges(contact, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('common.continue-without-saving'),
            body: this.intl.t('common.continue-without-saving-prompt', { resource: this.intl.t('resource.contact') }),
            acceptButtonText: this.intl.t('common.continue'),
            confirm: async () => {
                contact.rollbackAttributes();
                await this.hostRouter.transitionTo('console.fleet-ops.management.contacts.index.details', contact);
            },
            ...options,
        });
    }
}
