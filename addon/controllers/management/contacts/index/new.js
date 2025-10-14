import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ManagementContactsIndexNewController extends Controller {
    @service contactActions;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;
    @tracked contact = this.contactActions.createNewInstance();

    @task *save(contact) {
        try {
            yield contact.save();
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.management.contacts.index.details', contact);
            this.notifications.success(
                this.intl.t('common.resource-created-success-name', {
                    resource: this.intl.t('resource.contact'),
                    resourceName: contact.name,
                })
            );
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.contact = this.contactActions.createNewInstance();
    }
}
