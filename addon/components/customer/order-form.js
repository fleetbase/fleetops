import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, get, setProperties } from '@ember/object';
import { isArray } from '@ember/array';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class CustomerOrderFormComponent extends Component {
    @service orderCreation;
    @service customFieldsRegistry;
    @tracked customFields = null;

    @action cancelOrderCreation() {
        if (typeof this.args.onCancel === 'function') {
            this.args.onCancel();
        }
    }

    @task *loadCustomerOrderConfig() {
        try {
            this.orderConfigs = yield this.store.findAll('order-config');
        } catch (error) {
            this.notifications.serverError(error);
        }

        try {
            this.enabledOrderConfigs = yield this.fetch.get('fleet-ops/settings/customer-enabled-order-configs');
            this.orderConfigs = this.orderConfigs.filter((orderConfig) => this.enabledOrderConfigs.includes(orderConfig.id));
            if (this.orderConfigs) {
                this._setOrderConfig(this.orderConfigs[0].id);
            }
        } catch (error) {
            this.notifications.serverError(error);
        }

        if (!this.orderConfigs) {
            try {
                const defaultOrderConfig = yield this.fetch.get('orders/default-config', {}, { normalizeToEmberData: true, normalizeModelType: 'order-config' });
                if (defaultOrderConfig) {
                    this._setOrderConfig(defaultOrderConfig.id);
                }
            } catch (error) {
                this.notifications.serverError(error);
            }
        }

        try {
            const paymentsConfig = yield this.fetch.get('fleet-ops/settings/customer-payments-config');
            if (paymentsConfig) {
                this.paymentsEnabled = paymentsConfig.paymentsEnabled;
                this.paymentsOnboardCompleted = paymentsConfig.paymentsOnboardCompleted;
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *getQuotes() {
        if (this.loadCustomerOrderConfig.isRunning || this.urlSearchParams.has('checkout_session_id')) {
            return;
        }

        let payload = this.payload.serialize();
        let route = this.getRoute();
        let distance = get(route, 'details.summary.totalDistance');
        let time = get(route, 'details.summary.totalTime');
        let service_type = this.order.type;
        let scheduled_at = this.order.scheduled_at;
        let facilitator = this.order.facilitator?.get('public_id');
        let is_route_optimized = this.order.get('is_route_optimized');
        let { waypoints, entities } = this;
        let places = [];

        if (this.payloadCoordinates.length < 2) {
            return;
        }

        // get place instances from WaypointModel
        for (let i = 0; i < waypoints.length; i++) {
            let place = yield waypoints[i].place;

            places.pushObject(place);
        }

        setProperties(payload, { type: this.order.type, waypoints: places, entities });

        try {
            const serviceQuotes = yield this.fetch.post(
                'service-quotes/preliminary',
                {
                    payload: this._getSerializedPayload(payload),
                    distance,
                    time,
                    service,
                    service_type,
                    facilitator,
                    scheduled_at,
                    is_route_optimized,
                },
                { normalizeToEmberData: true, normalizeModelType: 'service-quote' }
            );

            this.serviceQuotes = isArray(serviceQuotes) ? serviceQuotes : [];
            if (this.serviceQuotes.length) {
                this.selectedServiceQuote = this.serviceQuotes.firstObject.id;
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *selectOrderConfig(orderConfig) {
        if (!orderConfig) return;
        this.setOrderConfig(orderConfig);

        try {
            yield this.getQuotes.perform();

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

    setOrderConfig(orderConfig) {
        this.args.resource.setProperties({
            order_config_uuid: orderConfig.id,
            order_config: orderConfig,
            type: orderConfig.key,
        });
        this.args.resource.payload.set('type', orderConfig.key);
    }
}
