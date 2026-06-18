import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { next } from '@ember/runloop';
import Evented from '@ember/object/evented';

export const SERVICE_QUOTE_REFRESH_REQUESTED = 'service-quote-refresh-requested';

export default class OrderCreationService extends Service.extend(Evented) {
    @service orderActions;
    @tracked context;
    @tracked order;
    @tracked cfManager;
    @tracked serviceQuoteOverrides = {};
    orderValidationRules = {};

    newOrder(attrs = {}) {
        const order = this.orderActions.createNewInstance(attrs);
        this.order = order;

        next(() => {
            this.addContext('order', order);
        });

        return order;
    }

    getContext(key) {
        return key ? this.context[key] : this.context;
    }

    addContext(key, value) {
        this.context = {
            ...this.context,
            [key]: value,
        };
        this[key] = value;
    }

    removeContext(key) {
        delete this.context[key];
    }

    requestServiceQuoteRefresh(reason, order = this.order) {
        this.trigger(SERVICE_QUOTE_REFRESH_REQUESTED, {
            reason,
            order,
        });
    }

    setServiceQuoteOverride(key, config = {}) {
        this.serviceQuoteOverrides = {
            ...this.serviceQuoteOverrides,
            [key]: {
                ...config,
                key,
            },
        };
    }

    getServiceQuoteOverride() {
        return Object.values(this.serviceQuoteOverrides ?? {}).find((override) => override?.mode === 'locked') ?? null;
    }

    clearServiceQuoteOverride(key) {
        const overrides = { ...(this.serviceQuoteOverrides ?? {}) };
        delete overrides[key];
        this.serviceQuoteOverrides = overrides;
    }

    setOrderValidationRule(key, fn) {
        if (typeof fn !== 'function') {
            return;
        }

        this.orderValidationRules = {
            ...(this.orderValidationRules ?? {}),
            [key]: fn,
        };
    }

    clearOrderValidationRule(key) {
        const rules = { ...(this.orderValidationRules ?? {}) };
        delete rules[key];
        this.orderValidationRules = rules;
    }

    validateOrderRules(order, cfManager = null) {
        return Object.values(this.orderValidationRules ?? {}).every((fn) => fn(order, cfManager) !== false);
    }
}
