import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class OrderFormServiceRateComponent extends Component {
    @service serviceRateActions;
    @tracked selectedRate;
    @tracked serviceRates = [];
    @tracked serviceQuotes = [];

    get isServicable() {
        return this.args.resource?.order_config && this.args.resource?.payloadCoordinates?.length >= 2;
    }

    /**
     * True when the order's facilitator is an IntegratedVendor (e.g.
     * ParcelPath / UPS Direct / USPS Direct). For these, the bridge layer
     * resolves rates server-side from the vendor's API instead of from
     * locally configured ServiceRate records, so the rate-selector
     * dropdown is hidden and quotes load directly when the toggle flips
     * on.
     */
    get isIntegratedVendorFacilitator() {
        return this.args.resource?.facilitator?.get?.('isIntegratedVendor') ?? false;
    }

    @task *queryServiceRates(toggled) {
        this.args.resource.servicable = toggled;
        if (!toggled) return;

        // Integrated-vendor path: skip the local ServiceRate query and fetch
        // quotes straight from the bound vendor (ParcelPath / UPS / USPS).
        if (this.isIntegratedVendorFacilitator) {
            yield this.getServiceQuotes.perform(null);
            return;
        }

        this.serviceRates = yield this.serviceRateActions.queryServiceRatesForOrder.perform(this.args.resource);
    }

    @task *getServiceQuotes(serviceRate) {
        this.selectedRate = serviceRate;
        this.serviceQuotes = yield this.serviceRateActions.getServiceQuotes.perform(serviceRate, this.args.resource);
    }
}
