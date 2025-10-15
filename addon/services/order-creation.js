import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { later } from '@ember/runloop';

export default class OrderCreationService extends Service {
    @service orderActions;
    @tracked context;
    @tracked order;
    @tracked cfManager;

    newOrder(attrs = {}) {
        const order = this.orderActions.createNewInstance(attrs);
        this.order = order;

        later(
            this,
            () => {
                this.addContext('order', order);
            },
            0
        );

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
}
