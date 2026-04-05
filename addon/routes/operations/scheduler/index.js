import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { isValid as isValidDate } from 'date-fns';
import { isNone } from '@ember/utils';

const getUnscheduledOrder = (order) => {
    return isNone(order.scheduled_at);
};

const getScheduledOrder = (order) => {
    return isValidDate(order.scheduled_at);
};

export default class OperationsSchedulerIndexRoute extends Route {
    @service store;

    model() {
        return this.store.query('order', { status: 'created', with: ['payload', 'driverAssigned.vehicle'] });
    }

    setupController(controller, model) {
        const orders = model.toArray();
        controller.unscheduledOrders = orders.filter(getUnscheduledOrder);
        controller.scheduledOrders = orders.filter(getScheduledOrder);
    }
}
