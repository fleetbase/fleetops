import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ManagementContactsCustomersNewController extends Controller {
    @service customerActions;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;
    @tracked customer = this.customerActions.createNewInstance();

    @task *save(customer) {
        try {
            yield customer.save();
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.management.contacts.customers.details', customer);
            this.notifications.success(
                this.intl.t('common.resource-created-success-name', {
                    resource: this.intl.t('resource.customer'),
                    resourceName: customer.name,
                })
            );
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.customer = this.customerActions.createNewInstance();
    }
}
