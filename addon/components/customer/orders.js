import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class CustomerOrdersComponent extends Component {
    @service store;
    @service notifications;
    @service currentUser;
    @tracked orders = [];
    @tracked selectedOrder;

    constructor() {
        super(...arguments);
        this.loadCustomerOrders.perform();
    }

    @task *loadCustomerOrders() {
        try {
            this.orders = yield this.store.query('order', { customer: this.currentUser.id });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action selectOrder(order) {
        this.selectedOrder = order;
    }
}
