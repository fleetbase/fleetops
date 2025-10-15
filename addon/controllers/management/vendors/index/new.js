import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ManagementVendorsIndexNewController extends Controller {
    @service vendorActions;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;
    @tracked vendor = this.vendorActions.createNewInstance();
    @tracked integratedVendor;

    @task *save(vendor) {
        vendor = this.integratedVendor ? this.integratedVendor : vendor;

        try {
            yield vendor.save();
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.management.vendors.index.details', vendor);
            this.notifications.success(
                this.intl.t('common.resource-created-success-name', {
                    resource: this.intl.t('resource.vendor'),
                    resourceName: vendor.name,
                })
            );
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.vendor = this.vendorActions.createNewInstance();
        this.integratedVendor = null;
    }

    @action setIntegration(integratedVendor) {
        this.integratedVendor = integratedVendor;
    }
}
