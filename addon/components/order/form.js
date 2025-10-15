import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class OrderFormComponent extends Component {
    @service store;
    @service orderConfigActions;
    @service customFieldsRegistry;
    @service leafletMapManager;
    @service currentUser;
    @tracked customFields;

    constructor() {
        super(...arguments);
        this.orderConfigActions.loadAll.perform();
    }

    @action selectFacilitator(model) {
        this.args.resource.set('facilitator', model);
        this.args.resource.set('driver', null);
    }

    @task *selectOrderConfig(orderConfig) {
        if (!orderConfig) return;
        this.args.resource.setProperties({
            order_config_uuid: orderConfig.id,
            order_config: orderConfig,
            type: orderConfig.key,
        });
        this.args.resource.payload.set('type', orderConfig.key);

        this.customFields = yield this.customFieldsRegistry.loadSubjectCustomFields.perform(orderConfig);
    }

    @task *selectDriver(driver) {
        this.args.resource.set('driver_assigned', driver);

        try {
            const vehicle = yield driver.vehicle;
            if (vehicle) {
                this.args.resource.set('vehicle_assigned', vehicle);
            }
        } catch (err) {
            debug('Unable to load and set driver vehicle: ' + err.message);
        }

        this.leafletMapManager.map.liveMap.focusDriver(driver);
        // if (this.args.resource.is_route_optimized) {
        //     this.optimizeRoute.perform();
        // }
    }

    @action toggleAdhoc(toggled) {
        this.args.resource.adhoc = toggled;
        this.args.resource.adhoc_distance = this.currentUser.getCompanyOption('fleetops.adhoc_distance', 5000);
    }

    @action toggleProofOfDelivery(toggled) {
        this.args.resource.pod_required = toggled;
        this.args.resource.pod_method = toggled ? 'scan' : null;
    }
}
