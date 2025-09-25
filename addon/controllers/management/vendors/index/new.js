import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

const DEFAULT_PROPERTIES = { status: 'active' };

export default class ManagementVendorsIndexNewController extends Controller {
    @service store;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;
    @tracked vendor = this.store.createRecord('vendor', DEFAULT_PROPERTIES);
    @tracked integratedVendor;

    @task *save(vendor) {
        vendor = this.integratedVendor ? this.integratedVendor : vendor;

        try {
            yield vendor.save();
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.management.vendors.index.details', vendor);
            this.notifications.success(this.intl.t('fleet-ops.component.vendor-form-panel.success-message', { vendorName: vendor.name }));
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.vendor = this.store.createRecord('vendor', DEFAULT_PROPERTIES);
        this.integratedVendor = null;
    }

    @action setIntegration(integratedVendor) {
        this.integratedVendor = integratedVendor;
    }
}
