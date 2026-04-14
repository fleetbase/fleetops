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
import toCalendarDate from '../../../utils/to-calendar-date';

/**
 * OperationsSchedulerIndexController
 *
 * Unified order dispatch board controller.
 * All scheduling domain logic is delegated to the injected `scheduling` service.
 *
 * Calendar library: @event-calendar/core (MIT licensed).
 * This replaces FullCalendar Premium resource-timeline plugins which are
 * incompatible with Fleetbase's dual AGPL v3 / commercial license.
 *
 * Timezone handling
 * -----------------
 * @event-calendar/core has no timezone support — it reads the browser-local
 * fields of any Date and positions events at that wall-clock time (see
 * https://github.com/vkurko/calendar/issues/576).
 *
 * The solution (as recommended by the maintainer) is to convert all UTC dates
 * to "fake local" Dates whose local fields equal the company wall-clock time
 * before passing them to the calendar.  This is done via `toCalendarDate()`.
 *
 * The same conversion is applied to:
 *   - Event start/end (order events and shift background blocks)
 *   - The `now` option (current-time indicator position)
 *   - The `date` option (which day is highlighted as "today")
 *
 * When the user drops or drags an event, the calendar returns a Date whose
 * local fields equal the visible wall-clock time.  `_reinterpretDateInTimezone`
 * converts that back to a true UTC instant for the API.
 *
 * Data flow:
 *   Route -> store.query() -> Ember Data store
 *   Controller computed getters -> store.peekAll() -> reactive UI
 *   Socket service -> store.pushPayload() -> reactive UI (no page refresh)
 *
 * External drag-and-drop:
 *   Sidebar cards use native HTML5 draggable="true".
 *   onSidebarDragStart stores the dragged order reference.
 *   onCalendarDrop uses calendar.dateFromPoint(x, y) to resolve the target
 *   date and resource, then delegates to SchedulingService.assignOrder().
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
    @tracked viewRange = 'week';
    @tracked searchQuery = '';
    @tracked activeFilters = [];
    @tracked selectedOrderIds = new Set();
    @tracked drivers = [];
    @tracked sidebarCollapsed = false;

    // Holds the order being dragged from the sidebar so onCalendarDrop can access it.
    _draggedOrder = null;

    // Revision counter — incremented after every successful save so that
    // @computed('_orderRevision') getters recompute even when Ember Data's
    // @each tracking misses a deep attribute change on an existing record.
    @tracked _orderRevision = 0;

    // -------------------------------------------------------------------------
    // Reactive Computed Getters
    // -------------------------------------------------------------------------

    @computed('_orderRevision', 'store')
    get allActiveOrders() {
        // Return all orders with an active status — no date window filtering.
        // The calendar renders only what falls in the visible range; unscheduled
        // orders appear in the sidebar panel. Past orders are kept for historical
        // context and future orders are always visible regardless of current view.
        const statuses = ['created', 'dispatched', 'active'];
        return this.store.peekAll('order').filter((order) => statuses.includes(order.status));
    }

    @computed('allActiveOrders.@each.scheduled_at', 'searchQuery', 'searchQuery.length', 'activeFilters.[]')
    get unscheduledOrders() {
        let orders = this.allActiveOrders.filter((o) => isNone(o.scheduled_at) || !isValidDate(new Date(o.scheduled_at)));
        if (this.searchQuery && this.searchQuery.length >= 2) {
            const q = this.searchQuery.toLowerCase();
            orders = orders.filter((o) => {
                return (o.public_id ?? '').toLowerCase().includes(q) || (o.tracking ?? '').toLowerCase().includes(q) || (o.payload?.dropoff?.address ?? '').toLowerCase().includes(q);
            });
        }
        this.activeFilters.forEach((filter) => {
            if (filter.type === 'type') orders = orders.filter((o) => o.type === filter.value);
            if (filter.type === 'priority') orders = orders.filter((o) => o.priority === filter.value);
        });
        return orders;
    }

    @computed('allActiveOrders.@each.{scheduled_at,driver_assigned_uuid,status}', 'currentUser.company.timezone', 'companyTimezone')
    get calendarEvents() {
        const tz = this.companyTimezone;
        return this.allActiveOrders.filter((o) => !isNone(o.scheduled_at) && isValidDate(new Date(o.scheduled_at))).map((o) => createFullCalendarEventFromOrder(o, tz));
    }

    @computed('drivers.[]', 'allActiveOrders.@each.{scheduled_at,driver_assigned_uuid}')
    get calendarResources() {
        return this.drivers.map((driver) => {
            const assignedCount = this.allActiveOrders.filter((o) => o.driver_assigned_uuid === driver.id && !isNone(o.scheduled_at)).length;
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

    @computed('drivers.@each.currentShift', 'currentUser.company.timezone', 'companyTimezone')
    get backgroundEvents() {
        const tz = this.companyTimezone;
        const events = [];
        this.drivers.forEach((driver) => {
            const shift = driver.currentShift;
            if (shift) {
                events.push(
                    createFullCalendarEventFromScheduleItem(shift, driver, tz, {
                        display: 'background',
                        backgroundColor: 'rgba(99, 102, 241, 0.08)',
                        borderColor: 'rgba(99, 102, 241, 0.25)',
                    })
                );
            }
        });
        return events;
    }

    @computed('calendarEvents.[]', 'backgroundEvents.[]')
    get allCalendarEvents() {
        return [...this.calendarEvents, ...this.backgroundEvents];
    }

    /**
     * The view name string passed to EventCalendar's @view arg.
     * @event-calendar/core uses 'resourceTimelineDay' / 'resourceTimelineWeek'
     * identical to FullCalendar's naming convention.
     */
    get currentCalendarView() {
        const viewMap = { day: 'resourceTimelineDay', week: 'resourceTimelineWeek' };
        return viewMap[this.viewRange] ?? 'resourceTimelineDay';
    }

    /**
     * Minimal header toolbar — only the date title is shown.
     * Navigation (prev/next/today) and view-range buttons are already
     * provided by the section header above the calendar, so we suppress
     * the duplicates here.
     */
    get calendarHeaderToolbar() {
        return { start: '', center: 'title', end: '' };
    }

    /**
     * Returns the IANA timezone string for the current organisation.
     * Falls back to the browser's local timezone when the company record has
     * not yet loaded or has no timezone set.
     *
     * @returns {string}  e.g. 'Asia/Singapore', 'America/New_York'
     */
    get companyTimezone() {
        return this.currentUser?.company?.timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone;
    }

    /**
     * Options passed to the EventCalendar @options arg.
     * These control display formatting only — timezone conversion is handled
     * by toCalendarDate() before events reach the calendar.
     *
     * @returns {object}
     */
    @computed('currentUser.company.timezone')
    get calendarOptions() {
        return {
            slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            dayHeaderFormat: { weekday: 'short', month: 'numeric', day: 'numeric' },
        };
    }

    /**
     * The current moment expressed as a "fake local" Date in the company
     * timezone.  Passed to the calendar as the `now` option so that the
     * current-time indicator appears at the correct position on the timeline.
     *
     * @returns {Date}
     */
    @computed('currentUser.company.timezone', 'companyTimezone')
    get calendarNow() {
        return toCalendarDate(new Date(), this.companyTimezone);
    }

    /** Full 24-hour day visible on the timeline. */
    get calendarSlotMinTime() {
        return '00:00:00';
    }

    get calendarSlotMaxTime() {
        return '24:00:00';
    }

    // -------------------------------------------------------------------------
    // EventCalendar Render Hooks
    // -------------------------------------------------------------------------

    /**
     * Renders the resource label cell for each driver row.
     * Returns an HTML string that EventCalendar injects into the label cell.
     * Shows driver name and a full-width capacity bar.
     */
    @action renderResourceLabel({ resource }) {
        const { driver, workload } = resource.extendedProps ?? {};
        if (!driver) return resource.title ?? '';
        const { assigned = 0, capacity = 10, percentage = 0 } = workload ?? {};
        const barColour = percentage >= 90 ? '#ef4444' : percentage >= 70 ? '#f59e0b' : '#6366f1';
        return {
            html: `<div class="ec-resource-label-inner" style="width:100%;box-sizing:border-box;padding:4px 8px;">
                <div style="font-size:0.75rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;width:100%;">${driver.name ?? ''}</div>
                <div style="display:flex;align-items:center;gap:4px;margin-top:3px;width:100%;">
                    <div style="flex:1;min-width:0;height:4px;background:#374151;border-radius:9999px;overflow:hidden;">
                        <div style="height:100%;width:${percentage}%;background:${barColour};border-radius:9999px;transition:width 0.3s;"></div>
                    </div>
                    <span style="font-size:0.625rem;color:#9ca3af;white-space:nowrap;flex-shrink:0;">${assigned}/${capacity}</span>
                </div>
            </div>`,
        };
    }

    /**
     * Renders the event tile content inside the timeline.
     * Returns an HTML string for order events; shift background events render
     * with no custom content (EventCalendar handles background display natively).
     * Shows: tracking number, status badge, driver name, scheduled time, destination.
     */
    @action renderEventContent({ event }) {
        if (event.display === 'background') return null;
        const { order, status } = event.extendedProps ?? {};
        const tracking = event.title ?? order?.tracking ?? order?.public_id ?? '';
        const driverName = order?.driver_assigned?.name ?? order?.get?.('driver_assigned.name') ?? '';
        const destination = order?.pickupName ?? order?.get?.('pickupName') ?? '';
        const scheduledTime = order?.scheduledAtTime ?? order?.get?.('scheduledAtTime') ?? '';
        const statusLabel = status ? status.charAt(0).toUpperCase() + status.slice(1) : '';
        const metaLine = [scheduledTime, driverName].filter(Boolean).join(' · ');
        return {
            html: `<div style="display:flex;flex-direction:column;gap:2px;padding:2px 0;overflow:hidden;height:100%;">
                <div style="display:flex;align-items:center;gap:4px;flex-wrap:nowrap;">
                    <span style="width:7px;height:7px;border-radius:50%;background:#ffffff;opacity:0.9;flex-shrink:0;"></span>
                    <span style="font-size:0.72rem;font-weight:700;color:#ffffff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0;">${tracking}</span>
                    <span style="font-size:0.58rem;background:rgba(255,255,255,0.2);color:#ffffff;border-radius:3px;padding:1px 4px;white-space:nowrap;flex-shrink:0;">${statusLabel}</span>
                </div>
                ${metaLine ? `<div style="font-size:0.65rem;color:rgba(255,255,255,0.85);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${metaLine}</div>` : ''}
                ${destination ? `<div style="font-size:0.65rem;color:rgba(255,255,255,0.7);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">→ ${destination}</div>` : ''}
            </div>`,
        };
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

    @action isOrderSelected(orderId) {
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
    // EventCalendar Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Receives the EventCalendar instance once it is mounted.
     * The instance exposes: setOption(), getOption(), prev(), next(),
     * getEventById(), removeEventById(), updateEvent(), dateFromPoint().
     */
    @action setCalendarApi(calendar) {
        this.calendar = calendar;
    }

    // -------------------------------------------------------------------------
    // Drag-and-Drop: External Drop from Sidebar (native HTML5)
    // -------------------------------------------------------------------------

    /**
     * Called on dragstart for each sidebar order card.
     * Stores the order reference so onCalendarDrop can retrieve it.
     */
    @action onSidebarDragStart(order, event) {
        this._draggedOrder = order;
        // Set a minimal dataTransfer payload as a fallback identifier.
        event.dataTransfer.setData('text/plain', order.id);
        event.dataTransfer.effectAllowed = 'move';
    }

    /**
     * Prevents the browser's default "no drop" behaviour so the drop event fires.
     * Also sets a data attribute on the timeline container to trigger the CSS
     * drag-over highlight, and moves a thin cursor line to the exact drop column.
     */
    @action onCalendarDragOver(event) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        const el = document.getElementById('fleet-ops-scheduler-timeline');
        if (!el) return;
        el.dataset.draggingOver = 'true';
        // Move the drop-cursor indicator to the current pointer X position.
        const ecMain = el.querySelector('.ec-main');
        if (ecMain) {
            let cursor = ecMain.querySelector('.ec-drop-cursor');
            if (!cursor) {
                cursor = document.createElement('div');
                cursor.className = 'ec-drop-cursor';
                ecMain.style.position = 'relative';
                ecMain.appendChild(cursor);
            }
            const rect = ecMain.getBoundingClientRect();
            const scrollLeft = ecMain.scrollLeft;
            cursor.style.left = event.clientX - rect.left + scrollLeft + 'px';
        }
    }

    /**
     * Clears the drag-over highlight and removes the cursor indicator when the
     * pointer genuinely exits the timeline container (not just moves to a child).
     */
    @action onCalendarDragLeave(event) {
        const el = document.getElementById('fleet-ops-scheduler-timeline');
        if (el && !el.contains(event.relatedTarget)) {
            delete el.dataset.draggingOver;
            const cursor = el.querySelector('.ec-drop-cursor');
            if (cursor) cursor.remove();
        }
    }

    /**
     * Handles a sidebar card being dropped onto the EventCalendar timeline.
     * Uses calendar.dateFromPoint(x, y) to resolve the target date and resource
     * from the drop coordinates — this is the @event-calendar/core equivalent
     * of FullCalendar's onDrop / eventReceive callback.
     *
     * dateFromPoint() returns a Date whose LOCAL fields equal the wall-clock
     * time the user sees on screen (because the calendar stored the "fake local"
     * dates we passed in, and returns them the same way).  We reinterpret those
     * local fields as a true UTC instant using the company timezone.
     */
    @action async onCalendarDrop(event) {
        event.preventDefault();
        // Clear the drag-over highlight and cursor indicator.
        const timelineEl = document.getElementById('fleet-ops-scheduler-timeline');
        if (timelineEl) {
            delete timelineEl.dataset.draggingOver;
            const cursor = timelineEl.querySelector('.ec-drop-cursor');
            if (cursor) cursor.remove();
        }
        const order = this._draggedOrder;
        this._draggedOrder = null;
        if (!order || !this.calendar) return;

        // Preserve scroll position so the drop doesn't reset the timeline view.
        const ecMain = timelineEl?.querySelector('.ec-main');
        const savedScrollLeft = ecMain ? ecMain.scrollLeft : 0;
        const savedScrollTop = ecMain ? ecMain.scrollTop : 0;

        // Resolve drop position to a date + resource using EventCalendar's API.
        const dropInfo = this.calendar.dateFromPoint(event.clientX, event.clientY);
        if (!dropInfo) return;
        const { date, resource } = dropInfo;
        const driverId = resource?.id ?? null;

        // dateFromPoint() returns a Date whose local fields equal the wall-clock
        // time visible on screen.  Convert to a true UTC instant for the API.
        const scheduledAt = date ? this._reinterpretDateInTimezone(date, this.companyTimezone) : new Date();

        const result = await this.scheduling.assignOrder(order, driverId, scheduledAt);

        // Bump the revision counter so allActiveOrders / unscheduledOrders
        // recompute immediately — this removes the order from the sidebar.
        if (!result.error) {
            this._orderRevision += 1;
        }

        // Restore scroll position after the calendar re-renders.
        if (ecMain) {
            requestAnimationFrame(() => {
                ecMain.scrollLeft = savedScrollLeft;
                ecMain.scrollTop = savedScrollTop;
            });
        }

        if (result.hasConflict) {
            this._showConflictModal(order, driverId, scheduledAt, result.conflicts);
        }
    }

    // -------------------------------------------------------------------------
    // Drag-and-Drop: Reschedule Existing Event (internal timeline drag)
    // -------------------------------------------------------------------------

    /**
     * Handles an existing calendar event being dragged to a new time/resource.
     * @event-calendar/core eventDrop info shape:
     *   { event, oldEvent, oldResource, newResource, delta, revert, jsEvent, view }
     * event.resourceIds[0] replaces FullCalendar's event.getResources()[0]?.id
     */
    @action async rescheduleEventFromDrag(info) {
        const { event, revert } = info;
        const { start, end, extendedProps } = event;
        const tz = this.companyTimezone;
        if (extendedProps?.scheduleItem) {
            // Shift block drag — update the ScheduleItem record directly.
            const scheduleItem = extendedProps.scheduleItem;
            const newResourceId = event.resourceIds?.[0];
            try {
                scheduleItem.set('start_at', this._reinterpretDateInTimezone(start, tz));
                scheduleItem.set('end_at', this._reinterpretDateInTimezone(end ?? start, tz));
                if (newResourceId) scheduleItem.set('assignee_uuid', newResourceId);
                await scheduleItem.save();
                this.notifications.success(this.intl.t('scheduler.shift-updated'));
            } catch (error) {
                this.notifications.serverError(error);
                revert();
            }
            return;
        }
        // Order event drag — delegate to SchedulingService.
        const order = this.store.peekRecord('order', event.id);
        if (!order) return;
        const newDriverId = event.resourceIds?.[0] ?? order.driver_assigned_uuid;
        const result = await this.scheduling.assignOrder(order, newDriverId, this._reinterpretDateInTimezone(start, tz));
        const tzStart = this._reinterpretDateInTimezone(start, tz);
        if (result.hasConflict) {
            revert();
            this._showConflictModal(order, newDriverId, tzStart, result.conflicts);
        } else if (result.error) {
            revert();
        } else {
            this._orderRevision += 1;
        }
    }

    // -------------------------------------------------------------------------
    // Event Click
    // -------------------------------------------------------------------------

    /**
     * @event-calendar/core eventClick info shape: { event, el, jsEvent, view }
     * Identical to FullCalendar — no changes needed to the info object access.
     */
    @action viewOrderAsEvent(info) {
        const { event } = info;
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
                            schedule = await this.store
                                .createRecord('schedule', {
                                    subject_type: 'driver',
                                    subject_uuid: targetDriver.id,
                                    name: `${targetDriver.name} Schedule`,
                                    timezone: this.companyTimezone,
                                    status: 'draft',
                                })
                                .save();
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

    @action undo() {
        return this.scheduling.undo();
    }

    @action redo() {
        return this.scheduling.redo();
    }

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

    _handleOrderSocketEvent({ data } = {}) {
        if (!data?.id) return;
        try {
            this.store.pushPayload('order', { order: data });
        } catch {
            /* ignore */
        }
    }

    _handleDriverSocketEvent({ event, data } = {}) {
        if (!data?.id) return;
        if (event === 'driver.location_changed') return;
        try {
            this.store.pushPayload('driver', { driver: data });
        } catch {
            /* ignore */
        }
    }

    // -------------------------------------------------------------------------
    // View Navigation
    // -------------------------------------------------------------------------

    /**
     * Navigation uses EventCalendar's setOption/getOption API:
     *   calendar.setOption('date', newDate)  replaces calendar.today() / gotoDate()
     *   calendar.getOption('date')           replaces calendar.getDate()
     *   calendar.setOption('view', viewName) replaces calendar.changeView()
     *   calendar.prev() / calendar.next()    are identical in both libraries
     */
    @action goToToday() {
        // Use the company-local "today" so the calendar highlights the correct day.
        this.viewDate = toCalendarDate(new Date(), this.companyTimezone);
        this.calendar?.setOption('date', this.viewDate);
    }

    @action goToPrev() {
        this.calendar?.prev();
        const d = this.calendar?.getOption('date');
        if (d) this.viewDate = d;
    }

    @action goToNext() {
        this.calendar?.next();
        const d = this.calendar?.getOption('date');
        if (d) this.viewDate = d;
    }

    @action setViewRange(range) {
        this.viewRange = range;
        // currentCalendarView getter returns the correct view name string.
        // EventCalendar re-renders reactively when @view arg changes, but we
        // also call setOption for immediate imperative update if needed.
        this.calendar?.setOption('view', this.currentCalendarView);
    }

    // -------------------------------------------------------------------------
    // Legacy helpers (adapted for @event-calendar/core API)
    // -------------------------------------------------------------------------

    /**
     * Removes an event from the calendar by ID.
     * @event-calendar/core uses removeEventById(id) instead of event.remove().
     */
    removeEvent(event) {
        if (isObject(event) && typeof event.id === 'string') {
            this.calendar?.removeEventById(event.id);
            return true;
        }
        if (isJson(event)) {
            event = JSON.parse(event);
            this.calendar?.removeEventById(event.id);
            return true;
        }
        if (typeof event === 'string') {
            this.calendar?.removeEventById(event);
            return true;
        }
        return false;
    }

    /**
     * Retrieves an event object from the calendar by ID.
     * @event-calendar/core uses getEventById(id) — same method name as FullCalendar.
     */
    getEvent(event) {
        if (isJson(event)) {
            event = JSON.parse(event);
            return this.calendar?.getEventById(event.id);
        }
        if (typeof event === 'string') return this.calendar?.getEventById(event);
        return event;
    }

    /**
     * Updates a single property on a calendar event.
     * @event-calendar/core uses updateEvent({...event, [prop]: value})
     * instead of FullCalendar's event.setProp(prop, value).
     */
    setEventProperty(event, prop, value) {
        const eventInstance = this.getEvent(event);
        if (eventInstance) {
            this.calendar?.updateEvent({ ...eventInstance, [prop]: value });
            return true;
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Timezone Utilities
    // -------------------------------------------------------------------------

    /**
     * Converts a Date whose **local** fields represent a wall-clock time back
     * into the correct UTC instant for that moment in the given timezone.
     *
     * This is the inverse of toCalendarDate().
     *
     * When the calendar returns a Date from dateFromPoint() or an eventDrop
     * callback, its local fields equal the wall-clock time the user sees on
     * screen (because we passed "fake local" Dates in and the library echoes
     * them back the same way).  We must convert those local fields to a true
     * UTC instant before sending to the API.
     *
     * Example: user drops at 22:30 on Apr 6 (visible on screen, SGT)
     *   date.getHours() === 22, date.getDate() === 6
     *   → returns a Date whose getUTCHours() === 14 (22:30 SGT = 14:30 UTC)
     *
     * @param {Date}   date      The Date returned by dateFromPoint() or eventDrop.
     * @param {string} timezone  IANA timezone string, e.g. 'Asia/Singapore'.
     * @returns {Date}
     */
    _reinterpretDateInTimezone(date, timezone) {
        try {
            // Read the local fields — these hold the wall-clock time the user
            // sees on screen.
            const y = date.getFullYear();
            const mo = date.getMonth() + 1;
            const d = date.getDate();
            const h = date.getHours();
            const mi = date.getMinutes();
            const s = date.getSeconds();

            // Build a UTC probe at the same wall-clock instant and ask Intl
            // what offset the target timezone applies at that moment.
            const wallClock = `${y}-${String(mo).padStart(2, '0')}-${String(d).padStart(2, '0')}T${String(h).padStart(2, '0')}:${String(mi).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
            const probe = new Date(`${wallClock}Z`);
            const parts = new Intl.DateTimeFormat('en-US', {
                timeZone: timezone,
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false,
            }).formatToParts(probe);

            const get = (type) => parseInt(parts.find((p) => p.type === type)?.value ?? '0', 10);
            const tzHour = get('hour') % 24;
            const probeLocal = Date.UTC(get('year'), get('month') - 1, get('day'), tzHour, get('minute'), get('second'));
            const offsetMs = probe.getTime() - probeLocal;

            const wallMs = Date.UTC(y, mo - 1, d, h, mi, s);
            return new Date(wallMs + offsetMs);
        } catch {
            return date;
        }
    }
}
