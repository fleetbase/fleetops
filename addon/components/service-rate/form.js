import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ServiceRateFormComponent extends Component {
    @service orderConfigActions;
    @service serviceRateActions;

    constructor() {
        super(...arguments);
        this.orderConfigActions.loadAll.perform();
    }

    @action selectOrderConfig(orderConfig) {
        this.args.resource.set('order_config', orderConfig);
        this.args.resource.set('order_config_uuid', orderConfig.id);
        this.args.resource.set('service_type', orderConfig.key);
    }

    @action selectRateCalculationMethod({ value: rateCalculationMethod }) {
        this.args.resource.set('rate_calculation_method', rateCalculationMethod);

        if (rateCalculationMethod === 'per_drop') {
            this.args.resource.resetPerDropFees();
        }
    }
}
