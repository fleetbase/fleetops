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

    @task *queryServiceRates(toggled) {
        this.args.resource.servicable = toggled;
        if (!toggled) return;
        this.serviceRates = yield this.serviceRateActions.queryServiceRatesForOrder.perform(this.args.resource);
    }

    @task *getServiceQuotes(serviceRate) {
        this.selectedRate = serviceRate;
        this.serviceQuotes = yield this.serviceRateActions.getServiceQuotes.perform(serviceRate, this.args.resource);
    }
}
