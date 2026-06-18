import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class OrderFormDetailsComponent extends Component {
    @service store;
    @service orderCreation;
    @service orderConfigActions;
    @service customFieldsRegistry;
    @service leafletMapManager;
    @service leafletLayerVisibilityManager;
    @service currentUser;
    @tracked customFields;

    constructor() {
        super(...arguments);
        this.orderConfigActions.loadAll.perform();
    }

    get integratedVendorServiceType() {
        return this.args.resource?.type;
    }

    @action selectFacilitator(model) {
        this.args.resource.set('facilitator', model);
        this.args.resource.set('driver', null);
        this.requestServiceQuoteRefresh('details.facilitator.changed');
    }

    @action selectCustomer(model) {
        this.args.resource.set('customer', model);
        this.args.resource.set('customer_uuid', model?.uuid ?? model?.id ?? null);
        this.args.resource.set('customer_type', model?.customer_type ? `fleet-ops:${model.customer_type}` : null);
    }

    @task *selectOrderConfig(orderConfig) {
        if (!orderConfig) return;
        this.args.resource.setProperties({
            order_config_uuid: orderConfig.id,
            order_config: orderConfig,
            type: orderConfig.key,
        });
        this.args.resource.payload.set('type', orderConfig.key);
        this.requestServiceQuoteRefresh('details.order_config.changed');

        try {
            const customFieldsManager = yield this.customFieldsRegistry.loadSubjectCustomFields.perform(orderConfig);
            this.orderCreation.addContext('cfManager', customFieldsManager);
            this.customFields = customFieldsManager;
            this.args.resource.cfManager = customFieldsManager;
            if (typeof this.args.onCustomFieldsReady === 'function') {
                this.args.onCustomFieldsReady(customFieldsManager);
            }
        } catch (err) {
            debug('Error loading order custom fields: ' + err.message);
        }
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

        // Show & track driver assigned
        this.leafletLayerVisibilityManager.hideCategory('drivers');
        this.leafletLayerVisibilityManager.showModelLayer(this.args.resource.driver_assigned);
    }

    @action setScheduledAt(value) {
        this.args.resource.scheduled_at = value;
        this.requestServiceQuoteRefresh('details.scheduled_at.changed');
    }

    @action selectIntegratedServiceType(serviceType) {
        const type = serviceType?.key ?? serviceType?.value ?? serviceType;
        this.args.resource.type = type;
        this.args.resource.payload.set('type', type);
        this.requestServiceQuoteRefresh('details.integrated_service_type.changed');
    }

    @action toggleAdhoc(toggled) {
        this.args.resource.adhoc = toggled;
        this.args.resource.adhoc_distance = this.currentUser.getCompanyOption('fleetops.adhoc_distance', 5000);
    }

    @action toggleProofOfDelivery(toggled) {
        this.args.resource.pod_required = toggled;
        this.args.resource.pod_method = toggled ? 'scan' : null;
    }

    requestServiceQuoteRefresh(reason) {
        this.orderCreation.requestServiceQuoteRefresh(reason, this.args.resource);
    }
}
