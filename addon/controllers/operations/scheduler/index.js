import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, computed } from '@ember/object';
import { isNone } from '@ember/utils';
import { isValid as isValidDate } from 'date-fns';
import { task } from 'ember-concurrency';
import isObject from '@fleetbase/ember-core/utils/is-object';
import isJson from '@fleetbase/ember-core/utils/is-json';
import createFullCalendarEventFromOrder from '../../../utils/create-full-calendar-event-from-order';
import createFullCalendarEventFromScheduleItem from '../../../utils/create-full-calendar-event-from-schedule-item';

/**
 * OperationsSchedulerIndexController
 *
 * Unified order dispatch board controller.
 * All scheduling domain logic is delegated to the injected `scheduling` service.
 *
 * Data flow:
 *   Route -> store.query() -> Ember Data store
 *   Controller computed getters -> store.peekAll() -> reactive UI
 *   Socket service -> store.pushPayload() -> reactive UI (no page refresh)
 */
export default class OperationsSchedulerIndexController extends Controller {

    @service scheduling;
    @service socket;
    @service store;
    @service notifications;
    @service modalsManager;
    @service currentUser;
    @service intl;
    @service fetch;

    // UI State
    @tracked calendar = null;
    @tracked viewDate = new Date();
    @tracked viewRange = 'day';
    @tracked searchQuery = '';
    @tracked activeFilters = [];
    @tracked selectedOrderIds = new Set();
    @tracked drivers = [];
    @tracked sidebarCollapsed = false;

    // -------------------------------------------------------------------------
    // Reactive Computed Getters
    // -------------------------------------------------------------------------

    @computed('store', 'viewDate')
    get allActiveOrders() {
        const viewDateStr = this.viewDate.toDateString();
        const statuses = ['created', 'dispatched', 'active'];
        return this.store.peekAll('order').filter((order) => {
            if (!statuses.includes(order.status)) return false;
            if (!isNone(order.scheduled_at) && isValidDate(new Date(order.scheduled_at))) {
                return new Date(order.scheduled_at).toDateString() === viewDateStr;
            }
            return true;
        });
    }

    @computed('allActiveOrders.@each.scheduled_at', 'searchQuery', 'activeFilters.[]')
    get unscheduledOrders() {
        let orders = this.allActiveOrders.filter((o) => isNone(o.scheduled_at) || !isValidDate(new Date(o.scheduled_at)));
        if (this.searchQuery && this.searchQuery.length >= 2) {
            const q = this.searchQuery.toLowerCase();
            orders = orders.filter((o) => {
                return (
                    (o.public_id ?? '').toLowerCase().includes(q) ||
                    (o.tracking ?? '').toLowerCase().includes(q) ||
                    (o.payload?.dropoff?.address ?? '').toLowerCase().includes(q)
                );
            });
        }
        this.activeFilters.forEach((filter) => {
            if (filter.type === 'type') orders = orders.filter((o) => o.type === filter.value);
            if (filter.type === 'priority') orders = orders.filter((o) => o.priority === filter.value);
        });
        return orders;
    }

    @computed('allActiveOrders.@each.{scheduled_at,driver_uuid,status}')
    get calendarEvents() {
        return this.allActiveOrders
            .filter((o) => !isNone(o.scheduled_at) && isValidDate(new Date(o.scheduled_at)))
            .map((o) => createFullCalendarEventFromOrder(o));
    }

    @computed('drivers.[]', 'allActiveOrders.@each.{scheduled_at,driver_uuid}')
    get calendarResources() {
        return this.drivers.map((driver) => {
            const assignedCount = this.allActiveOrders.filter(
                (o) => o.driver_uuid === driver.id && !isNone(o.scheduled_at)
            ).length;
            const maxCapacity = driver.max_daily_orders ?? 10;
            const pct = Math.round((assignedCount / maxCapacity) * 100);
            return {
                id: driver.id,
                title: driver.name,
                extendedProps: {
                    driver,
                    workload: { assigned: assignedCount, capacity: maxCapacity, percentage: Math.min(pct, 100) },
                },
            };
        });
    }

    @computed('drivers.@each.currentShift')
    get backgroundEvents() {
        const events = [];
        this.drivers.forEach((driver) => {
            const shift = driver.currentShift;
            if (shift) {
                events.push(createFullCalendarEventFromScheduleItem(shift, driver, {
                    display: 'background',
                    backgroundColor: 'rgba(99, 102, 241, 0.08)',
                    borderColor: 'rgba(99, 102, 241, 0.25)',
                }));
            }
        });
        return events;
    }

    @computed('calendarEvents.[]', 'backgroundEvents.[]')
    get allCalendarEvents() {
        return [...this.calendarEvents, ...this.backgroundEvents];
    }

    // -------------------------------------------------------------------------
    // Sidebar Selection
    // -------------------------------------------------------------------------

    get selectedOrders() {
        return this.unscheduledOrders.filter((o) => this.selectedOrderIds.has(o.id));
    }

    get hasSelection() {
        return this.selectedOrderIds.size > 0;
    }

    isOrderSelected(orderId) {
        return this.selectedOrderIds.has(orderId);
    }

    @action toggleOrderSelection(orderId) {
        const next = new Set(this.selectedOrderIds);
        next.has(orderId) ? next.delete(orderId) : next.add(orderId);
        this.selectedOrderIds = next;
    }

    @action selectAllOrders() {
        this.selectedOrderIds = new Set(this.unscheduledOrders.map((o) => o.id));
    }

    @action clearSelection() {
        this.selectedOrderIds = new Set();
    }

    // -------------------------------------------------------------------------
    // Debounced Sidebar Search
    // -------------------------------------------------------------------------

    @task({ restartable: true })
    *searchTask(query) {
        yield new Promise((resolve) => setTimeout(resolve, 300));
        this.searchQuery = query;
    }

    @action onSearchInput(event) {
        this.searchTask.perform(event.target.value);
    }

    @action clearSearch() {
        this.searchQuery = '';
    }

    // -------------------------------------------------------------------------
    // FullCalendar Lifecycle
    // -------------------------------------------------------------------------

    @action setCalendarApi(calendar) {
        this.calendar = calendar;
    }

    // -------------------------------------------------------------------------
    // Drag-and-Drop: Drop from Sidebar
    // -------------------------------------------------------------------------

    @action async scheduleEventFromDrop(dropInfo) {
        const { draggedEl, date, resource } = dropInfo;
        const eventDataStr = draggedEl.dataset.event ?? '{}';
        let data = {};
        if (isJson(eventDataStr)) data = JSON.parse(eventDataStr);
        const order = this.store.peekRecord('order', data.id);
        if (!order) return;

        const driverId = resource?.id ?? null;
        let scheduledAt = date;

        if (driverId && !dropInfo.dateStr?.includes('T')) {
            scheduledAt = await this.scheduling.findBestFit(driverId, order);
        }

        const result = await this.scheduling.assignOrder(order, driverId, scheduledAt);
        if (result.hasConflict) {
            this._showConflictModal(order, driverId, scheduledAt, result.conflicts);
        }
    }

    // -------------------------------------------------------------------------
    // Drag-and-Drop: Reschedule Existing Event
    // -------------------------------------------------------------------------

    @action async rescheduleEventFromDrag(eventDropInfo) {
        const { event, revert } = eventDropInfo;
        const { start, end, extendedProps } = event;

        if (extendedProps?.scheduleItem) {
            const scheduleItem = extendedProps.scheduleItem;
            const newResourceId = event.getResources()[0]?.id;
            try {
                scheduleItem.set('start_at', start);
                scheduleItem.set('end_at', end ?? start);
                if (newResourceId) scheduleItem.set('assignee_uuid', newResourceId);
                await scheduleItem.save();
                this.notifications.success(this.intl.t('scheduler.shift-updated'));
            } catch (error) {
                this.notifications.serverError(error);
                revert();
            }
            return;
        }

        const order = this.store.peekRecord('order', event.id);
        if (!order) return;

        const newDriverId = event.getResources()[0]?.id ?? order.driver_uuid;
        const result = await this.scheduling.assignOrder(order, newDriverId, start);

        if (result.hasConflict) {
            revert();
            this._showConflictModal(order, newDriverId, start, result.conflicts);
        } else if (result.error) {
            revert();
        }
    }

    // -------------------------------------------------------------------------
    // Event Click
    // -------------------------------------------------------------------------

    @action viewOrderAsEvent(eventClickInfo) {
        const { event } = eventClickInfo;
        if (event.extendedProps?.scheduleItem) return this._viewShiftEvent(event);
        const order = this.store.peekRecord('order', event.id);
        if (order) this.viewEvent(order);
    }

    @action viewEvent(order) {
        this.modalsManager.show('modals/order-event', {
            title: this.intl.t('scheduler.scheduling-for', { orderId: order.tracking ?? order.public_id }),
            acceptButtonText: this.intl.t('common.save-changes'),
            acceptButtonIcon: 'save',
            hideDeclineButton: true,
            order,
            reschedule: (date) => {
                if (date && typeof date.toDate === 'function') date = date.toDate();
                order.set('scheduled_at', date);
            },
            unschedule: async (modalsManager, done) => {
                modalsManager.startLoading();
                await this.scheduling.unscheduleOrder(order);
                done();
            },
            confirm: async (modalsManager, done) => {
                modalsManager.startLoading();
                if (!order.get('hasDirtyAttributes')) return done();
                try {
                    await order.save();
                    if (order.scheduled_at) {
                        this.notifications.success(this.intl.t('scheduler.success-message', { orderId: order.public_id, orderAt: order.scheduledAt }));
                    } else {
                        this.notifications.info(this.intl.t('scheduler.info-message', { orderId: order.public_id }));
                    }
                    done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modalsManager.stopLoading();
                }
            },
        });
    }

    _viewShiftEvent(event) {
        const { scheduleItem, driver } = event.extendedProps;
        this.modalsManager.show('modals/driver-shift', {
            title: driver ? `${driver.name} — ${this.intl.t('scheduler.shift')}` : this.intl.t('scheduler.shift'),
            scheduleItem,
            driver,
            confirm: async (modalsManager, done) => {
                modalsManager.startLoading();
                try {
                    await scheduleItem.save();
                    this.notifications.success(this.intl.t('scheduler.shift-updated'));
                    done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modalsManager.stopLoading();
                }
            },
            delete: async (modalsManager, done) => {
                modalsManager.startLoading();
                try {
                    await scheduleItem.destroyRecord();
                    this.notifications.success(this.intl.t('scheduler.shift-deleted'));
                    done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modalsManager.stopLoading();
                }
            },
        });
    }

    // -------------------------------------------------------------------------
    // Add Driver Shift
    // -------------------------------------------------------------------------

    @action addDriverShift() {
        this.modalsManager.show('modals/add-driver-shift', {
            title: this.intl.t('scheduler.add-shift'),
            acceptButtonText: this.intl.t('scheduler.create-shift'),
            acceptButtonIcon: 'plus',
            drivers: this.drivers,
            confirm: async (modalsManager, done) => {
                modalsManager.startLoading();
                const options = modalsManager.getOptions();
                const targetDriver = options.selectedDriver;
                try {
                    if (options.isRecurring) {
                        const template = this.store.createRecord('schedule-template', {
                            name: options.templateName || `${targetDriver?.name} Recurring Schedule`,
                            rrule: options.rrule,
                            start_time: options.shiftStartTime,
                            end_time: options.shiftEndTime,
                            break_start_time: options.breakStartTime || null,
                            break_end_time: options.breakEndTime || null,
                            color: options.templateColor || '#6366f1',
                        });
                        const savedTemplate = await template.save();
                        const schedules = await this.store.query('schedule', { subject_type: 'driver', subject_uuid: targetDriver.id, limit: 1 });
                        let schedule;
                        if (schedules.length > 0) {
                            schedule = schedules.firstObject;
                        } else {
                            schedule = await this.store.createRecord('schedule', {
                                subject_type: 'driver',
                                subject_uuid: targetDriver.id,
                                name: `${targetDriver.name} Schedule`,
                                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                                status: 'draft',
                            }).save();
                        }
                        await this.fetch.post(`schedule-templates/${savedTemplate.id}/apply`, {
                            subject_type: 'driver',
                            subject_uuid: targetDriver.id,
                            schedule_uuid: schedule.id,
                            effective_from: options.recurrenceStartDate || new Date().toISOString(),
                            effective_until: options.recurrenceEndDate || null,
                        });
                        this.notifications.success(this.intl.t('scheduler.recurring-schedule-created'));
                    } else {
                        const scheduleItem = this.store.createRecord('schedule-item', {
                            assignee_type: 'driver',
                            assignee_uuid: targetDriver?.id,
                            title: options.title || null,
                            start_at: options.startAt,
                            end_at: options.endAt,
                            notes: options.notes || null,
                            status: 'scheduled',
                        });
                        await scheduleItem.save();
                        this.notifications.success(this.intl.t('scheduler.shift-created'));
                    }
                    done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modalsManager.stopLoading();
                }
            },
        });
    }

    // -------------------------------------------------------------------------
    // Bulk Operations
    // -------------------------------------------------------------------------

    @action openBulkAssignModal() {
        if (!this.hasSelection) return;
        const orders = this.selectedOrders;
        this.modalsManager.show('modals/bulk-assign-orders', {
            title: this.intl.t('scheduler.bulk-assign-title', { count: orders.length }),
            orders,
            drivers: this.drivers,
            confirm: async (modalsManager, done) => {
                modalsManager.startLoading();
                const { driver, date } = modalsManager.getOptions();
                try {
                    await this.scheduling.bulkAssign(orders, driver.id, date);
                    this.clearSelection();
                    this.notifications.success(this.intl.t('scheduler.bulk-assign-success', { count: orders.length }));
                    done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modalsManager.stopLoading();
                }
            },
        });
    }

    // -------------------------------------------------------------------------
    // Conflict Resolution
    // -------------------------------------------------------------------------

    _showConflictModal(order, driverId, scheduledAt, conflicts) {
        const driver = this.store.peekRecord('driver', driverId);
        this.modalsManager.show('modals/scheduling-conflict', {
            title: this.intl.t('scheduler.conflict-title'),
            order,
            driver,
            conflicts,
            scheduledAt,
            assignAnyway: async (modalsManager, done) => {
                modalsManager.startLoading();
                await this.scheduling.assignOrder(order, driverId, scheduledAt, { skipConflictCheck: true });
                done();
            },
            autoAdjust: async (modalsManager, done) => {
                modalsManager.startLoading();
                const bestFit = await this.scheduling.findBestFit(driverId, order);
                await this.scheduling.assignOrder(order, driverId, bestFit, { skipConflictCheck: true });
                done();
            },
        });
    }

    // -------------------------------------------------------------------------
    // Undo / Redo
    // -------------------------------------------------------------------------

    @action undo() { return this.scheduling.undo(); }
    @action redo() { return this.scheduling.redo(); }

    // -------------------------------------------------------------------------
    // Real-Time Socket Subscriptions
    // -------------------------------------------------------------------------

    @action async subscribeToRealTimeUpdates() {
        const orgId = this.currentUser?.companyId ?? this.currentUser?.company?.id;
        if (!orgId) return;
        await this.socket.listen(`company.${orgId}.orders`, (payload) => this._handleOrderSocketEvent(payload));
        this.drivers.forEach(async (driver) => {
            await this.socket.listen(`driver.${driver.id}`, (payload) => this._handleDriverSocketEvent(payload));
        });
    }

    @action unsubscribeFromRealTimeUpdates() {
        if (this.socket && typeof this.socket.closeChannels === 'function') {
            this.socket.closeChannels();
        }
    }

    _handleOrderSocketEvent({ event, data } = {}) {
        if (!data?.id) return;
        try { this.store.pushPayload('order', { order: data }); } catch { /* ignore */ }
    }

    _handleDriverSocketEvent({ event, data } = {}) {
        if (!data?.id) return;
        if (event === 'driver.location_changed') return;
        try { this.store.pushPayload('driver', { driver: data }); } catch { /* ignore */ }
    }

    // -------------------------------------------------------------------------
    // View Navigation
    // -------------------------------------------------------------------------

    @action goToToday() { this.viewDate = new Date(); this.calendar?.today(); }
    @action goToPrev() { this.calendar?.prev(); const d = this.calendar?.getDate(); if (d) this.viewDate = d; }
    @action goToNext() { this.calendar?.next(); const d = this.calendar?.getDate(); if (d) this.viewDate = d; }

    @action setViewRange(range) {
        this.viewRange = range;
        const viewMap = { day: 'resourceTimelineDay', week: 'resourceTimelineWeek' };
        this.calendar?.changeView(viewMap[range] ?? 'resourceTimelineDay');
    }

    // -------------------------------------------------------------------------
    // Legacy helpers
    // -------------------------------------------------------------------------

    removeEvent(event) {
        if (isObject(event) && typeof event.remove === 'function') { event.remove(); return true; }
        if (isObject(event) && typeof event.id === 'string') return this.removeEvent(event.id);
        if (isJson(event)) { event = JSON.parse(event); return this.removeEvent(event.id); }
        if (typeof event === 'string') {
            const calEvent = this.calendar?.getEventById(event);
            if (calEvent && typeof calEvent.remove === 'function') { calEvent.remove(); return true; }
        }
        return false;
    }

    getEvent(event) {
        if (isJson(event)) { event = JSON.parse(event); return this.calendar?.getEventById(event.id); }
        if (typeof event === 'string') return this.calendar?.getEventById(event);
        return event;
    }

    setEventProperty(event, prop, value) {
        const eventInstance = this.getEvent(event);
        if (eventInstance && typeof eventInstance.setProp === 'function') { eventInstance.setProp(prop, value); return true; }
        return false;
    }
}
