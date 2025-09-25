import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { task } from 'ember-concurrency';
import serializePayload from '../utils/serialize-payload';

export default class ServiceRateActionsService extends ResourceActionService {
    @task *queryServiceRatesForOrder(order, params = {}) {
        const queryParams = {
            coordinates: order.payload.payloadCoordinates.join(';'),
            facilitator: order.facilitator?.get('isIntegratedVendor') ? order.facilitator.get('public_id') : null,
            service_type: order.order_config?.get('key'),
            ...params,
        };

        try {
            const serviceRates = yield this.fetch.get('service-rates/for-route', queryParams, { normalizeToEmberData: true, normalizeModelType: 'service-rate' });
            serviceRates.unshiftObject({
                service_name: 'Quote from all Service Rates',
                id: 'all',
            });

            return serviceRates;
        } catch (err) {
            console.error(err);
            this.notifications.serverError(err);
        }
    }

    @task *getServiceQuotes(serviceRate, order) {
        if (order.payload.payloadCoordinates.length < 2) return;

        try {
            const serviceQuotes = yield this.fetch.post('service-quotes/preliminary', {
                payload: serializePayload(order.payload),
                distance: order.route.summary?.totalDistance,
                time: order.route.summary?.totalTime,
                service_type: order.facilitator ? (this.args.facilitator.get('service_types.firstObject.key') ?? order.type) : order.type,
                facilitator: order.facilitator?.public_id,
                scheduled_at: order.scheduled_at,
                is_route_optimized: order.optimized,
                service: serviceRate.id,
            });

            return serviceQuotes;
        } catch (err) {
            console.error(err);
            this.notifications.serverError(err);
        }
    }
}
