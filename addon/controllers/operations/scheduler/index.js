import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { computed, action } from '@ember/object';
import { format, isValid as isValidDate } from 'date-fns';

export default class OperationsSchedulerIndexController extends Controller {
    @service modalsManager;
    @service notifications;

    @computed('model.@each.scheduled_at') get scheduledOrders() {
        return this.model.filter((order) => isValidDate(order.scheduled_at));
    }

    @computed('model.@each.scheduled_at') get unscheduledOrders() {
        return this.model.filter((order) => !order.scheduled_at);
    }

    @computed('scheduledOrders.@each.scheduled_at') get events() {
        return this.scheduledOrders.map((order) => ({
            id: order.id,
            title: `${order.scheduledAtTime} - ${order.public_id}`,
            start: order.scheduled_at,
            allDay: true,
        }));
    }

    @action viewEvent(order, eventClickInfo) {
        const { event } = eventClickInfo;

        this.modalsManager.show('modals/order-event', {
            title: `Scheduling for ${order.public_id}`,
            acceptButtonText: 'Save Changes',
            acceptButtonIcon: 'save',
            hideDeclineButton: true,
            order,
            reschedule: (date) => {
                if (typeof date?.toDate === 'function') {
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
                    this.notifications.success(`'${order.public_id}' has been updated.`);

                    // update event props
                    event?.setProp('title', order.eventTitle);

                    // remove event from calendar if unscheduled
                    if (!order.scheduled_at || !isValidDate(order.scheduled_at)) {
                        event.remove();
                    }
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
        order.save();
    }

    @action receivedEvent(eventReceivedInfo) {
        const { event } = eventReceivedInfo;
        const order = this.store.peekRecord('order', event.id);

        // update event props
        event?.setProp('title', order.eventTitle);
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
        order.save();

        // update event props
        event?.setProp('title', order.eventTitle);
    }
}
