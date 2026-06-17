import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task, timeout } from 'ember-concurrency';
import { SERVICE_QUOTE_REFRESH_REQUESTED } from '../../../services/order-creation';

const SERVICE_QUOTE_REFRESH_DEBOUNCE_MS = 500;

export default class OrderFormServiceRateComponent extends Component {
    @service serviceRateActions;
    @service orderCreation;
    @tracked selectedRate;
    @tracked serviceRates = [];
    @tracked serviceQuotes = [];
    @tracked isAutoRefreshingServiceQuotes = false;
    serviceQuoteRefreshRequestId = 0;

    constructor() {
        super(...arguments);
        this._serviceQuoteRefreshRequested = (event) => this.handleServiceQuoteRefreshRequest(event);
        this.orderCreation.on(SERVICE_QUOTE_REFRESH_REQUESTED, this._serviceQuoteRefreshRequested);
    }

    willDestroy() {
        this.orderCreation.off(SERVICE_QUOTE_REFRESH_REQUESTED, this._serviceQuoteRefreshRequested);
        this.refreshServiceQuotes.cancelAll();
        super.willDestroy(...arguments);
    }

    get isServicable() {
        return this.args.resource?.order_config && this.args.resource?.payloadCoordinates?.length >= 2;
    }

    get canRefreshServiceQuotes() {
        return this.args.resource?.servicable && this.selectedRate && this.args.resource?.payloadCoordinates?.length >= 2;
    }

    get hasServiceQuotes() {
        return this.serviceQuotes?.length > 0;
    }

    get isLoadingServiceQuotes() {
        return this.getServiceQuotes.isRunning || this.isAutoRefreshingServiceQuotes;
    }

    get shouldShowServiceQuotesLoader() {
        return this.isLoadingServiceQuotes && !this.hasServiceQuotes;
    }

    @task *queryServiceRates(toggled) {
        this.args.resource.servicable = toggled;
        if (!toggled) return;
        this.serviceRates = yield this.serviceRateActions.queryServiceRatesForOrder.perform(this.args.resource);
    }

    @task *getServiceQuotes(serviceRate) {
        this.selectedRate = serviceRate;
        yield this.loadServiceQuotes(serviceRate);
    }

    @task *refreshServiceQuotes(refreshRequestId) {
        this.isAutoRefreshingServiceQuotes = true;

        try {
            yield timeout(SERVICE_QUOTE_REFRESH_DEBOUNCE_MS);

            if (!this.canRefreshServiceQuotes) {
                return;
            }

            yield this.loadServiceQuotes(this.selectedRate);
        } finally {
            if (refreshRequestId === this.serviceQuoteRefreshRequestId) {
                this.isAutoRefreshingServiceQuotes = false;
            }
        }
    }

    handleServiceQuoteRefreshRequest({ order } = {}) {
        if (order && order !== this.args.resource) {
            return;
        }

        if (!this.canRefreshServiceQuotes) {
            return;
        }

        this.refreshServiceQuotes.cancelAll();
        this.serviceQuoteRefreshRequestId++;
        this.isAutoRefreshingServiceQuotes = true;
        this.refreshServiceQuotes.perform(this.serviceQuoteRefreshRequestId);
    }

    async loadServiceQuotes(serviceRate) {
        const serviceQuotes = await this.serviceRateActions.getServiceQuotes.perform(serviceRate, this.args.resource);
        this.serviceQuotes = serviceQuotes ?? [];
        this.clearStaleSelectedServiceQuote();
    }

    clearStaleSelectedServiceQuote() {
        const selectedServiceQuote = this.args.resource?.service_quote_uuid;

        if (!selectedServiceQuote) {
            return;
        }

        if (!this.serviceQuotes?.length) {
            this.args.resource.service_quote_uuid = null;
            return;
        }

        const selectedQuoteExists = this.serviceQuotes.some((serviceQuote) => {
            return serviceQuote?.uuid === selectedServiceQuote || serviceQuote?.id === selectedServiceQuote;
        });

        if (!selectedQuoteExists) {
            this.args.resource.service_quote_uuid = null;
        }
    }
}
