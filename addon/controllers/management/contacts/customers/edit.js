import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ManagementContactsCustomersEditController extends Controller {
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

    @task *save(customer) {
        try {
            yield customer.save();
            this.overlay?.close();

            yield this.hostRouter.transitionTo('console.fleet-ops.management.contacts.customers.details', customer);
            this.notifications.success(
                this.intl.t('common.resource-updated-success', {
                    resource: this.intl.t('resource.customer'),
                    resourceName: customer.name,
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

        return this.hostRouter.transitionTo('console.fleet-ops.management.contacts.customers');
    }

    @action view() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.management.contacts.customers.details', this.model);
    }

    #confirmContinueWithUnsavedChanges(customer, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('common.continue-without-saving'),
            body: this.intl.t('common.continue-without-saving-prompt', { resource: this.intl.t('resource.customer') }),
            acceptButtonText: this.intl.t('common.continue'),
            confirm: async () => {
                customer.rollbackAttributes();
                await this.hostRouter.transitionTo('console.fleet-ops.management.contacts.customers.details', customer);
            },
            ...options,
        });
    }
}
