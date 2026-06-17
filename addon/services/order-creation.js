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
}
