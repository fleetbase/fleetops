import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ServiceRateFormComponent extends Component {
    @service orderConfigActions;
    @service serviceAreaActions;
    @service serviceRateActions;

    constructor() {
        super(...arguments);
        this.orderConfigActions.loadAll.perform();
        this.serviceAreaActions.loadAll.perform();
    }

    get serviceAreas() {
        return this.serviceAreaActions.serviceAreas ?? [];
    }

    get zones() {
        return Array.from(this.serviceAreas).flatMap((serviceArea) => serviceArea.zones?.toArray?.() ?? serviceArea.zones ?? []);
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
        } else if (rateCalculationMethod === 'fixed_meter' || rateCalculationMethod === 'fixed_rate') {
            this.serviceRateActions.generateFixedRateFees(this.args.resource);
        } else if (rateCalculationMethod === 'multi_zone_distance' && !this.args.resource.rateFees.length) {
            this.args.resource.addMultiZoneDistanceRule();
            this.args.resource.addMultiZoneDistanceFallbackRule();
        }
    }

    @action onMaxDistanceChange() {
        this.serviceRateActions.generateFixedRateFees(this.args.resource);
    }

    @action selectRuleServiceArea(rule, serviceArea) {
        rule.set('service_area', serviceArea);
        rule.set('service_area_uuid', serviceArea?.id);
        rule.set('zone', null);
        rule.set('zone_uuid', null);
        rule.set('is_fallback', false);
    }

    @action selectRuleZone(rule, zone) {
        rule.set('zone', zone);
        rule.set('zone_uuid', zone?.id);
        rule.set('service_area', null);
        rule.set('service_area_uuid', null);
        rule.set('is_fallback', false);
    }

    @action setRuleFallback(rule, isFallback) {
        rule.set('is_fallback', Boolean(isFallback));
        if (isFallback) {
            rule.set('zone', null);
            rule.set('zone_uuid', null);
            rule.set('service_area', null);
            rule.set('service_area_uuid', null);
        }
    }
}
