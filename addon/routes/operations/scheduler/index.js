import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { isValid as isValidDate } from 'date-fns';
import { isNone } from '@ember/utils';
import createFullCalendarEventFromOrder from '../../../utils/create-full-calendar-event-from-order';

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
        // get orders
        const orders = model.toArray();

        // set unscheduled orders
        controller.unscheduledOrders = orders.filter(getUnscheduledOrder);

        // set scheduled orders
        controller.scheduledOrders = orders.filter(getScheduledOrder);

        // create events from scheduledOrders
        controller.events = controller.scheduledOrders.map(createFullCalendarEventFromOrder);
    }
}
