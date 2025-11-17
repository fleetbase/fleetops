import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, computed } from '@ember/object';
import { later } from '@ember/runloop';
import { task } from 'ember-concurrency';
import { format, isValid as isValidDate } from 'date-fns';
import isObject from '@fleetbase/ember-core/utils/is-object';
import isJson from '@fleetbase/ember-core/utils/is-json';
import createFullCalendarEventFromOrder, { createOrderEventTitle } from '../../../utils/create-full-calendar-event-from-order';

function createFullCalendarEventFromScheduleItem(item, driver) {
    return {
        id: item.id,
        resourceId: driver.id,
        title: `${driver.name} - Shift`,
        start: item.start_at,
        end: item.end_at,
        backgroundColor: getScheduleItemColor(item),
        extendedProps: {
            scheduleItem: item,
            driver: driver,
        },
    };
}

function getScheduleItemColor(item) {
    const statusColors = {
        pending: '#FFA500',
        confirmed: '#4CAF50',
        in_progress: '#2196F3',
        completed: '#9E9E9E',
        cancelled: '#F44336',
        no_show: '#FF5722',
    };
    return statusColors[item.status] || '#4CAF50';
}

export default class OperationsSchedulerIndexController extends Controller {
    @service modalsManager;
    @service notifications;
    @service store;
    @service intl;
    @service hostRouter;
    @service scheduling;
    @tracked scheduledOrders = [];
    @tracked unscheduledOrders = [];
    @tracked drivers = [];
    @tracked scheduleItems = [];
    @tracked viewMode = 'orders'; // 'orders' or 'drivers'

    @computed('drivers', 'scheduleItems.[]', 'scheduledOrders.[]', 'viewMode') get events() {
        if (this.viewMode === 'drivers') {
            return this.scheduleItems.map((item) => {
                const driver = this.drivers.find((d) => d.id === item.assignee_uuid);
                return createFullCalendarEventFromScheduleItem(item, driver);
            });
        }
        return this.scheduledOrders.map(createFullCalendarEventFromOrder);
    }

    @computed('drivers.[]') get calendarResources() {
        return this.drivers.map((driver) => ({
            id: driver.id,
            title: driver.name,
            extendedProps: { driver },
        }));
    }

    get calendarStartDate() {
        const now = new Date();
        const dayOfWeek = now.getDay();
        const diff = now.getDate() - dayOfWeek;
        return new Date(now.setDate(diff)).toISOString();
    }

    get calendarEndDate() {
        const now = new Date();
        return new Date(now.setDate(now.getDate() + 28)).toISOString();
    }

    @task *loadDrivers() {
        try {
            const drivers = yield this.store.query('driver', { limit: 100 });
            this.drivers = drivers.toArray();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *loadScheduleItems() {
        try {
            const items = yield this.store.query('schedule-item', {
                assignee_type: 'driver',
                start_at_after: this.calendarStartDate,
                end_at_before: this.calendarEndDate,
            });
            this.scheduleItems = items.toArray();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action setCalendarApi(calendar) {
        this.calendar = calendar;
        // setup some custom post initialization stuff here
        // calendar.setOption('height', 800);
    }

    @action viewEvent(order) {
        // get the event from the calendar
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
                    // remove event from calendar
                    if (event) {
                        this.removeEvent(event);
                    }

                    if (order.scheduled_at) {
                        // notify order has been scheduled
                        this.notifications.success(this.intl.t('scheduler.info-message', { orderId: order.public_id, orderAt: order.scheduledAt }));
                        // add event to calendar
                        event = this.calendar.addEvent(createFullCalendarEventFromOrder(order));
                    } else {
                        this.notifications.info(this.intl.t('scheduler.info-message', { orderId: order.public_id }));
                    }

                    // update event props
                    this.setEventProperty(event, 'title', createOrderEventTitle(order));

                    // refresh route
                    return this.hostRouter.refresh();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action async switchViewMode(mode) {
        this.viewMode = mode;
        if (mode === 'drivers') {
            await this.loadDrivers.perform();
            await this.loadScheduleItems.perform();
            later(() => {
                if (this.calendar) {
                    this.calendar.changeView('resourceTimelineWeek');
                }
            }, 100);
        } else {
            later(() => {
                if (this.calendar) {
                    this.calendar.changeView('dayGridMonth');
                }
            }, 100);
        }
    }

    @action viewOrderAsEvent(eventClickInfo) {
        const { event } = eventClickInfo;
        if (event.extendedProps && event.extendedProps.scheduleItem) {
            return this.viewScheduleItem(event.extendedProps.scheduleItem, event.extendedProps.driver);
        }
        const order = this.store.peekRecord('order', event.id);
        this.viewEvent(order, eventClickInfo);
    }

    @action viewScheduleItem(scheduleItem, driver) {
        this.modalsManager.show('modals/driver-shift', {
            title: `${driver.name} - Shift Details`,
            acceptButtonText: 'Save Changes',
            acceptButtonIcon: 'save',
            scheduleItem,
            driver,
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await scheduleItem.save();
                    this.notifications.success('Shift updated successfully');
                    await this.loadScheduleItems.perform();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
            delete: async (modal) => {
                if (confirm('Are you sure you want to delete this shift?')) {
                    modal.startLoading();
                    try {
                        await scheduleItem.destroyRecord();
                        this.notifications.success('Shift deleted successfully');
                        await this.loadScheduleItems.perform();
                        modal.done();
                    } catch (error) {
                        this.notifications.serverError(error);
                        modal.stopLoading();
                    }
                }
            },
        });
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
    }

    @action async rescheduleEventFromDrag(eventDropInfo) {
        const { event } = eventDropInfo;
        const { start, end } = event;

        if (event.extendedProps && event.extendedProps.scheduleItem) {
            const scheduleItem = event.extendedProps.scheduleItem;
            const newResourceId = event.getResources()[0]?.id;
            try {
                scheduleItem.set('start_at', start);
                scheduleItem.set('end_at', end || start);
                if (newResourceId && newResourceId !== scheduleItem.assignee_uuid) {
                    scheduleItem.set('assignee_uuid', newResourceId);
                }
                await scheduleItem.save();
                this.notifications.success('Shift rescheduled successfully');
                await this.loadScheduleItems.perform();
            } catch (error) {
                this.notifications.serverError(error);
                eventDropInfo.revert();
            }
            return;
        }

        const order = this.store.peekRecord('order', event.id);
        const scheduledTime = order.scheduledAtTime;
        const newDate = new Date(`${format(start, 'PP')} ${scheduledTime}`);

        try {
            order.set('scheduled_at', isValidDate(newDate) ? newDate : start);
            await order.save();
            this.setEventProperty(event, 'title', createOrderEventTitle(order));
            return this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
            this.removeEvent(event);
        }
    }

    @action async addDriverShift() {
        this.modalsManager.show('modals/add-driver-shift', {
            title: 'Add Driver Shift',
            acceptButtonText: 'Create Shift',
            acceptButtonIcon: 'plus',
            drivers: this.drivers,
            confirm: async (modal) => {
                modal.startLoading();
                const { driver, startAt, endAt, duration } = modal.getOptions();
                try {
                    const scheduleItem = this.store.createRecord('schedule-item', {
                        assignee_type: 'driver',
                        assignee_uuid: driver.id,
                        start_at: startAt,
                        end_at: endAt,
                        duration: duration,
                        status: 'pending',
                    });
                    await scheduleItem.save();
                    this.notifications.success('Shift created successfully');
                    await this.loadScheduleItems.perform();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
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
