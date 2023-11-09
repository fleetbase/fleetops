import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { format, isValid as isValidDate } from 'date-fns';
import createFullCalendarEventFromOrder from '../../../utils/create-full-calendar-event-from-order';

export default class OperationsSchedulerIndexController extends Controller {
    @service modalsManager;
    @service notifications;
    @service store;
    @service hostRouter;
    @tracked scheduledOrders = [];
    @tracked unscheduledOrders = [];
    @tracked events = [];

    @action setCalendarApi(calendar) {
        this.calendar = calendar;
    }

    @action viewEvent(order) {
        // get the event from the calendar
        let event = this.calendar.getEventById(order.id);

        this.modalsManager.show('modals/order-event', {
            title: `Scheduling for ${order.public_id}`,
            acceptButtonText: 'Save Changes',
            acceptButtonIcon: 'save',
            hideDeclineButton: true,
            order,
            reschedule: (date) => {
                if (date && typeof date.toDate === 'function') {
                    date = date.toDate();
                }

                order.set('scheduled_at', date);
            },
            unschedule: () => {
                order.set('scheduled_at', null);
            },
            confirm: (modal) => {
                modal.startLoading();

                if (!order.get('hasDirtyAttributes')) {
                    return modal.done();
                }

                return order.save().then((order) => {
                    // remove event from calendar
                    if (event) {
                        event.remove();
                    }

                    if (order.scheduled_at) {
                        // notify order has been scheduled
                        this.notifications.success(`Order '${order.public_id}' has been scheduled at ${order.scheduledAt}.`);
                        // add event to calendar
                        event = this.calendar.addEvent(createFullCalendarEventFromOrder(order));
                    } else {
                        this.notifications.info(`Order '${order.public_id}' has been unscheduled.`);
                    }

                    // update event props
                    if (event && typeof event.setProp === 'function') {
                        event.setProp('title', order.eventTitle);
                    }

                    // refresh route
                    this.hostRouter.refresh();
                });
            },
        });
    }

    @action viewOrderAsEvent(eventClickInfo) {
        const { event } = eventClickInfo;
        const order = this.store.peekRecord('order', event.id);

        this.viewEvent(order, eventClickInfo);
    }

    @action scheduleEventFromDrop(dropInfo) {
        const { draggedEl, date } = dropInfo;
        const { dataset } = draggedEl;
        const { event } = dataset;
        const data = JSON.parse(event);
        const order = this.store.peekRecord('order', data.id);

        order.set('scheduled_at', date);
        return order.save().then(() => {
            this.hostRouter.refresh();
        });
    }

    @action receivedEvent(eventReceivedInfo) {
        const { event } = eventReceivedInfo;
        const order = this.store.peekRecord('order', event.id);

        // update event props
        if (typeof event.setProp === 'function') {
            event.setProp('title', order.eventTitle);
        }
    }

    @action rescheduleEventFromDrag(eventDropInfo) {
        const { event } = eventDropInfo;
        const { start } = event;
        const order = this.store.peekRecord('order', event.id);

        // retain time, only change date
        const scheduledTime = order.scheduledAtTime;
        const newDate = new Date(`${format(start, 'PP')} ${scheduledTime}`);

        // set and save order props
        order.set('scheduled_at', isValidDate(newDate) ? newDate : start);
        order.save().then(() => {
            this.hostRouter.refresh();
        });

        // update event props
        if (typeof event.setProp === 'function') {
            event.setProp('title', order.eventTitle);
        }
    }
}
