import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { task } from 'ember-concurrency';
import { isNone } from '@ember/utils';
import serializePayload from '../utils/serialize-payload';

export default class ServiceRateActionsService extends ResourceActionService {
    modelNamePath = 'service_name';

    constructor() {
        super(...arguments);
        this.initialize('service-rate', {
            defaultAttributes: {
                rate_calculation_method: 'per_meter',
                per_meter_unit: 'm',
                currency: this.currentUser.currency,
                parcel_fees: this.#getDefaultParcelFees(),
            },
        });
    }

    transition = {
        view: (serviceRate) => this.transitionTo('operations.service-rates.index.details', serviceRate),
        edit: (serviceRate) => this.transitionTo('operations.service-rates.index.edit', serviceRate),
        create: () => this.transitionTo('operations.service-rates.index.new'),
    };

    panel = {
        create: (attributes = {}) => {
            const serviceRate = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'service-rate/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.service rate')?.toLowerCase() }),
                useDefaultSaveTask: true,
                saveOptions: {
                    callback: this.refresh,
                },
                serviceRate,
            });
        },
        edit: (serviceRate) => {
            return this.resourceContextPanel.open({
                content: 'service-rate/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: serviceRate.name }),
                useDefaultSaveTask: true,
                serviceRate,
            });
        },
        view: (serviceRate) => {
            return this.resourceContextPanel.open({
                serviceRate,
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'service-rate/details',
                    },
                ],
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const serviceRate = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: serviceRate,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.service rate')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.service-rate') }),
                component: 'service-rate/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', serviceRate, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (serviceRate, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: serviceRate,
                title: this.intl.t('common.edit-resource-name', { resourceName: serviceRate.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'service-rate/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', serviceRate, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (serviceRate, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: serviceRate,
                title: serviceRate.name,
                component: 'service-rate/details',
                ...options,
            });
        },
    };

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

        const hasFacilitator = !isNone(order.facilitator);
        const facilitatorServiceType = order.facilitator?.get('service_types.firstObject.key') ?? order.type;

        try {
            const serviceQuotes = yield this.fetch.post('service-quotes/preliminary', {
                payload: serializePayload(order.payload),
                distance: order.route.summary?.totalDistance,
                time: order.route.summary?.totalTime,
                service_type: hasFacilitator ? facilitatorServiceType : order.type,
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

    #getDefaultParcelFees() {
        const defaults = [
            {
                size: 'small',
                length: 34,
                width: 18,
                height: 10,
                dimensions_unit: 'cm',
                weight: 2,
                weight_unit: 'kg',
                fee: 0,
                currency: this.currentUser.currency,
            },
            {
                size: 'medium',
                length: 34,
                width: 32,
                height: 10,
                dimensions_unit: 'cm',
                weight: 4,
                weight_unit: 'kg',
                fee: 0,
                currency: this.currentUser.currency,
            },
            {
                size: 'large',
                length: 34,
                width: 32,
                height: 18,
                dimensions_unit: 'cm',
                weight: 8,
                weight_unit: 'kg',
                fee: 0,
                currency: this.currentUser.currency,
            },
            {
                size: 'x-large',
                length: 34,
                width: 32,
                height: 34,
                dimensions_unit: 'cm',
                weight: 13,
                weight_unit: 'kg',
                fee: 0,
                currency: this.currentUser.currency,
            },
        ];
        return defaults.map((fee) => this.store.createRecord('service-rate-parcel-fee', fee));
    }
}
