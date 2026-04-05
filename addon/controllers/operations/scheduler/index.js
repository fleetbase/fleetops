import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, computed } from '@ember/object';
import { format, isValid as isValidDate } from 'date-fns';
import { Tooltip } from '@fleetbase/ember-ui/utils/floating';
import isObject from '@fleetbase/ember-core/utils/is-object';
import isJson from '@fleetbase/ember-core/utils/is-json';
import createFullCalendarEventFromOrder, { createOrderEventTitle, createOrderEventDescription } from '../../../utils/create-full-calendar-event-from-order';

export default class OperationsSchedulerIndexController extends Controller {
    @service modalsManager;
    @service notifications;
    @service store;
    @service intl;
    @service hostRouter;

    @tracked scheduledOrders = [];
    @tracked unscheduledOrders = [];

    @computed('scheduledOrders.[]') get events() {
        return this.scheduledOrders.map(createFullCalendarEventFromOrder);
    }

    @action setCalendarApi(calendar) {
        this.calendar = calendar;

        calendar.setOption('eventDidMount', (info) => {
            if (!info.event.extendedProps.description) return;

            info.tooltip = new Tooltip(info.el, {
                text: info.event.extendedProps.description,
            });
        });

        calendar.setOption('eventWillUnmount', (info) => {
            info.tooltip?.destroy();
        });
    }

    @action viewEvent(order) {
        let event = this.calendar.getEventById(order.id);

        this.modalsManager.show('modals/order-event', {
            title: this.intl.t('scheduler.scheduling-for', { orderId: order.tracking }),
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
            confirm: async (modal) => {
                modal.startLoading();

                if (!order.get('hasDirtyAttributes')) {
                    return modal.done();
                }

                try {
                    await order.save();

                    if (event) {
                        this.removeEvent(event);
                    }

                    if (order.scheduled_at) {
                        this.notifications.success(this.intl.t('scheduler.success-message', { orderId: order.public_id, orderAt: order.scheduledAt }));
                        event = this.calendar.addEvent(createFullCalendarEventFromOrder(order));
                    } else {
                        this.notifications.info(this.intl.t('scheduler.info-message', { orderId: order.public_id }));
                    }

                    this.setEventProperty(event, 'title', createOrderEventTitle(order));
                    this.setEventProperty(event, 'description', createOrderEventDescription(order));

                    return this.hostRouter.refresh();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action viewOrderAsEvent(eventClickInfo) {
        const { event } = eventClickInfo;
        const order = this.store.peekRecord('order', event.id);
        if (order) {
            this.viewEvent(order);
        }
    }

    @action async scheduleEventFromDrop(dropInfo) {
        const { draggedEl, date } = dropInfo;
        const { dataset } = draggedEl;
        const { event } = dataset;
        const data = JSON.parse(event);
        const order = this.store.peekRecord('order', data.id);

        try {
            order.set('scheduled_at', date);
            await order.save();
            return this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
            this.removeEvent(event);
        }
    }

    @action receivedEvent(eventReceivedInfo) {
        const { event } = eventReceivedInfo;
        const order = this.store.peekRecord('order', event.id);

        this.setEventProperty(event, 'title', createOrderEventTitle(order));
        this.setEventProperty(event, 'description', createOrderEventDescription(order));
    }

    @action async rescheduleEventFromDrag(eventDropInfo) {
        const { event } = eventDropInfo;
        const { start } = event;

        const order = this.store.peekRecord('order', event.id);
        const scheduledTime = order.scheduledAtTime;
        const newDate = new Date(`${format(start, 'PP')} ${scheduledTime}`);

        try {
            order.set('scheduled_at', isValidDate(newDate) ? newDate : start);
            await order.save();
            this.setEventProperty(event, 'title', createOrderEventTitle(order));
            this.setEventProperty(event, 'description', createOrderEventDescription(order));
            return this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
            this.removeEvent(event);
        }
    }

    removeEvent(event) {
        if (isObject(event) && typeof event.remove === 'function') {
            event.remove();
            return true;
        }

        if (isObject(event) && typeof event.id === 'string') {
            return this.removeEvent(event.id);
        }

        if (isJson(event)) {
            event = JSON.parse(event);
            return this.removeEvent(event.id);
        }

        if (typeof event === 'string') {
            event = this.calendar.getEventById(event);
            if (typeof event.remove === 'function') {
                event.remove();
                return true;
            }
        }

        return false;
    }

    getEvent(event) {
        if (isJson(event)) {
            event = JSON.parse(event);
            return this.calendar.getEventById(event.id);
        }

        if (typeof event === 'string') {
            return this.calendar.getEventById(event);
        }

        return event;
    }

    setEventProperty(event, prop, value) {
        const eventInstance = this.getEvent(event);
        if (typeof eventInstance.setProp === 'function') {
            eventInstance.setProp(prop, value);
            return true;
        }

        return false;
    }
}
