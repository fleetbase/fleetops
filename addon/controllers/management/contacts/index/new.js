import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

const DEFAULT_PROPERTIES = { type: 'contact', status: 'active' };

export default class ManagementContactsIndexNewController extends Controller {
    @service store;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;
    @tracked contact = this.store.createRecord('contact', DEFAULT_PROPERTIES);

    @task *save(contact) {
        try {
            yield contact.save();
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.management.contacts.index.details', contact);
            this.notifications.success(this.intl.t('fleet-ops.component.contact-form-panel.success-message', { contactName: contact.name }));
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.contact = this.store.createRecord('contact', DEFAULT_PROPERTIES);
    }
}
