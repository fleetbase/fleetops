import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

const DEFAULT_PROPERTIES = { type: 'customer', status: 'active' };

export default class ManagementContactsCustomersNewController extends Controller {
    @service store;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;
    @tracked customer = this.store.createRecord('contact', DEFAULT_PROPERTIES);

    @task *save(customer) {
        try {
            yield customer.save();
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.management.contacts.customers.details', customer);
            this.notifications.success(this.intl.t('fleet-ops.component.contact-form-panel.success-message', { contactName: customer.name }));
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.customer = this.store.createRecord('contact', DEFAULT_PROPERTIES);
    }
}
