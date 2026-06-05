import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class ModalsConvertCustomerToVendorComponent extends Component {
    @service fetch;
    @service notifications;

    @tracked customer;
    @tracked vendorName;
    @tracked vendorEmail;
    @tracked vendorPhone;
    @tracked options = {};

    constructor(owner, { options }) {
        super(...arguments);
        this.customer = options.customer;
        this.vendorName = this.customer?.name;
        this.vendorEmail = this.customer?.email;
        this.vendorPhone = this.customer?.phone;
        this.options = options;
        this.setupOptions();
    }

    setupOptions() {
        this.options.title = 'Convert Contact to Vendor';
        this.options.acceptButtonText = 'Create Vendor';
        this.options.confirm = async (modal) => {
            modal.startLoading();

            try {
                const response = await this.fetch.post(
                    `contacts/${this.customer.id}/convert-to-vendor`,
                    {
                        name: this.vendorName,
                        email: this.vendorEmail,
                        phone: this.vendorPhone,
                    },
                    { namespace: 'int/v1' }
                );

                this.notifications.success('Customer converted to vendor account.');

                if (typeof this.options.onConverted === 'function') {
                    this.options.onConverted(response.vendor);
                }

                modal.done();
            } catch (error) {
                this.notifications.serverError(error);
                modal.stopLoading();
            }
        };
    }
}
