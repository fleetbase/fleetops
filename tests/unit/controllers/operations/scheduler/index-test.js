import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';
import { waitUntil } from '@ember/test-helpers';

/**
 * The dispatch board's controller. It has no template of its own worth rendering — the calendar is
 * `@event-calendar/core`, which the controller only ever talks to through a small imperative API
 * (`setOption`, `getOption`, `prev`, `next`, …). A unit test with a stand-in for that API exercises
 * the controller's own logic without pretending to run the calendar.
 */
module('Unit | Controller | operations/scheduler/index', function (hooks) {
    setupTest(hooks);

    hooks.afterEach(function () {
        this.timeline?.remove();
    });

    hooks.beforeEach(function () {
        const test = this;
        this.companyTimezone = 'Asia/Singapore';
        this.owner.register(
            'service:current-user',
            class extends Service {
                get company() {
                    return { timezone: test.companyTimezone };
                }
            }
        );

        this.store = this.owner.lookup('service:store');
        this.controller = this.owner.lookup('controller:operations/scheduler/index');

        /**
         * Push an order into the store the way the route's `store.query` would. `meta` defaults to
         * an empty object because `OrderModel#pickupName` guards `payload` with `?.` but then reads
         * `meta.pickup_is_driver_location` bare — an order pushed without `meta` throws there, and
         * `createFullCalendarEventFromOrder` reads `pickupName` for every scheduled order.
         */
        this.givenOrder = (attrs) => {
            return test.store.push(test.store.normalize('order', { uuid: attrs.id ?? attrs.uuid, meta: {}, ...attrs }));
        };

        this.assignments = [];
        this.assignResult = { error: null, hasConflict: false };
        this.owner.register(
            'service:scheduling',
            class extends Service {
                assignOrder(order, driverId, scheduledAt) {
                    test.assignments.push({ order, driverId, scheduledAt });
                    return Promise.resolve(test.assignResult);
                }
                undo() {
                    test.assignments.push('undo');
                    return 'undone';
                }
                redo() {
                    test.assignments.push('redo');
                    return 'redone';
                }
            }
        );

        this.channels = [];
        this.channelsClosed = 0;
        this.owner.register(
            'service:socket',
            class extends Service {
                listen(channel, handler) {
                    test.channels.push({ channel, handler });
                    return Promise.resolve();
                }
                closeChannels() {
                    test.channelsClosed += 1;
                }
            }
        );

        this.notified = [];
        this.owner.register(
            'service:notifications',
            class extends Service {
                success(message) {
                    test.notified.push(['success', message]);
                }
                serverError(error) {
                    test.notified.push(['serverError', error?.message ?? error]);
                }
            }
        );

        this.modals = [];
        this.owner.register(
            'service:modals-manager',
            class extends Service {
                show(name, options) {
                    test.modals.push([name, options]);
                }
                done() {
                    return Promise.resolve();
                }
            }
        );

        /**
         * The drag handlers reach for `#fleet-ops-scheduler-timeline` on the global document
         * rather than through a component element, so the test puts a real one there.
         */
        this.timeline = document.createElement('div');
        this.timeline.id = 'fleet-ops-scheduler-timeline';
        this.ecMain = document.createElement('div');
        this.ecMain.className = 'ec-main';
        this.timeline.appendChild(this.ecMain);
        document.getElementById('ember-testing').appendChild(this.timeline);

        /** A stand-in for the EventCalendar instance the template hands back on mount. */
        this.calendarEventsById = {};
        this.calendarCalls = [];
        this.calendarOptions = { date: new Date('2026-03-10T00:00:00Z') };
        this.calendarApi = {
            dateFromPoint(x, y) {
                test.calendarCalls.push(['dateFromPoint', x, y]);
                return test.dropInfo;
            },
            removeEventById(id) {
                test.calendarCalls.push(['removeEventById', id]);
            },
            getEventById(id) {
                test.calendarCalls.push(['getEventById', id]);
                return test.calendarEventsById[id];
            },
            updateEvent(event) {
                test.calendarCalls.push(['updateEvent', event]);
            },
            setOption(key, value) {
                test.calendarCalls.push(['setOption', key, value]);
                test.calendarOptions[key] = value;
            },
            getOption(key) {
                test.calendarCalls.push(['getOption', key]);
                return test.calendarOptions[key];
            },
            prev() {
                test.calendarCalls.push(['prev']);
                test.calendarOptions.date = new Date('2026-03-03T00:00:00Z');
            },
            next() {
                test.calendarCalls.push(['next']);
                test.calendarOptions.date = new Date('2026-03-17T00:00:00Z');
            },
        };
    });

    test('only active orders are on the board', function (assert) {
        this.givenOrder({ id: 'o_created', status: 'created', public_id: 'ORD_1' });
        this.givenOrder({ id: 'o_dispatched', status: 'dispatched', public_id: 'ORD_2' });
        this.givenOrder({ id: 'o_active', status: 'active', public_id: 'ORD_3' });
        this.givenOrder({ id: 'o_completed', status: 'completed', public_id: 'ORD_4' });
        this.givenOrder({ id: 'o_canceled', status: 'canceled', public_id: 'ORD_5' });

        assert.deepEqual(this.controller.allActiveOrders.map((o) => o.id).sort(), ['o_active', 'o_created', 'o_dispatched'], 'completed and canceled orders are left off');
    });

    test('an order with no usable scheduled date is unscheduled', function (assert) {
        this.givenOrder({ id: 'o_none', status: 'created', public_id: 'ORD_1' });
        this.givenOrder({ id: 'o_scheduled', status: 'created', public_id: 'ORD_2', scheduled_at: '2026-03-10T09:00:00Z' });

        assert.deepEqual(
            this.controller.unscheduledOrders.map((o) => o.id),
            ['o_none'],
            'a scheduled order belongs on the calendar, not in the sidebar'
        );
        assert.deepEqual(
            this.controller.calendarEvents.map((e) => e.extendedProps.order.id),
            ['o_scheduled'],
            'and the other way round'
        );
    });

    test('the sidebar search matches on id, tracking or destination, and needs two characters', function (assert) {
        this.givenOrder({ id: 'o_1', status: 'created', public_id: 'ORD_ALPHA', tracking: 'TRK111' });
        this.givenOrder({ id: 'o_2', status: 'created', public_id: 'ORD_BETA', tracking: 'TRK222' });

        this.controller.searchQuery = 'a';
        assert.strictEqual(this.controller.unscheduledOrders.length, 2, 'a single character is not enough to filter on');

        this.controller.searchQuery = 'alpha';
        assert.deepEqual(
            this.controller.unscheduledOrders.map((o) => o.id),
            ['o_1'],
            'the public id matches, case-insensitively'
        );

        this.controller.searchQuery = 'trk222';
        assert.deepEqual(
            this.controller.unscheduledOrders.map((o) => o.id),
            ['o_2'],
            'and so does the tracking number'
        );

        this.controller.searchQuery = 'nothing-like-this';
        assert.deepEqual(this.controller.unscheduledOrders, [], 'a query that matches nothing empties the sidebar');
    });

    test('the sidebar type filter narrows what is listed', function (assert) {
        this.givenOrder({ id: 'o_1', status: 'created', public_id: 'ORD_1', type: 'transport' });
        this.givenOrder({ id: 'o_2', status: 'created', public_id: 'ORD_2', type: 'storage' });
        this.givenOrder({ id: 'o_3', status: 'created', public_id: 'ORD_3', type: 'transport' });

        this.controller.activeFilters = [{ type: 'type', value: 'transport' }];
        assert.deepEqual(this.controller.unscheduledOrders.map((o) => o.id).sort(), ['o_1', 'o_3'], 'only the matching type is left');

        this.controller.activeFilters = [{ type: 'type', value: 'nothing-like-this' }];
        assert.deepEqual(this.controller.unscheduledOrders, [], 'a type nothing carries empties the sidebar');
    });

    test('the priority filter matches nothing, whatever it is given', function (assert) {
        this.givenOrder({ id: 'o_1', status: 'created', public_id: 'ORD_1', type: 'transport' });
        this.givenOrder({ id: 'o_2', status: 'created', public_id: 'ORD_2', type: 'transport' });

        // DEFECTS #103: the arm reads `o.priority`, and OrderModel has no such attribute — it has
        // `orchestrator_priority`. Every order reads back `undefined`, so no value can ever match.
        this.controller.activeFilters = [{ type: 'priority', value: 'high' }];
        assert.deepEqual(this.controller.unscheduledOrders, [], 'nothing survives a priority filter');
    });

    test('each driver becomes a row carrying their workload', function (assert) {
        const busy = this.store.push(this.store.normalize('driver', { uuid: 'd_busy', name: 'Ada' }));
        const idle = this.store.push(this.store.normalize('driver', { uuid: 'd_idle', name: 'Grace' }));
        this.controller.drivers = [busy, idle];

        this.givenOrder({ id: 'o_1', status: 'created', public_id: 'ORD_1', scheduled_at: '2026-03-10T09:00:00Z', driver_assigned_uuid: 'd_busy' });
        this.givenOrder({ id: 'o_2', status: 'created', public_id: 'ORD_2', scheduled_at: '2026-03-10T11:00:00Z', driver_assigned_uuid: 'd_busy' });
        // Unscheduled work does not count against the day's capacity.
        this.givenOrder({ id: 'o_3', status: 'created', public_id: 'ORD_3', driver_assigned_uuid: 'd_busy' });

        const [first, second] = this.controller.calendarResources;
        assert.strictEqual(first.title, 'Ada');
        assert.deepEqual(first.extendedProps.workload, { assigned: 2, capacity: 10, percentage: 20 }, 'two of a default ten');
        assert.deepEqual(second.extendedProps.workload, { assigned: 0, capacity: 10, percentage: 0 }, 'and a driver with nothing on');
    });

    test('a workload over capacity is still reported as full, not more than full', function (assert) {
        const driver = this.store.push(this.store.normalize('driver', { uuid: 'd_1', name: 'Ada' }));
        this.controller.drivers = [driver];
        // DEFECTS #102: `driver.max_daily_orders` is not an attribute on DriverModel, so the
        // capacity is always the fallback 10. Eleven scheduled orders is what it takes to go over.
        for (let i = 0; i < 11; i++) {
            this.givenOrder({ id: `o_${i}`, status: 'created', public_id: `ORD_${i}`, scheduled_at: '2026-03-10T09:00:00Z', driver_assigned_uuid: 'd_1' });
        }

        const [row] = this.controller.calendarResources;
        assert.deepEqual(row.extendedProps.workload, { assigned: 11, capacity: 10, percentage: 100 }, 'the bar caps at 100%');
    });

    test('the resource label shows the driver and colours the bar by how full it is', function (assert) {
        const label = (percentage) =>
            this.controller.renderResourceLabel({ resource: { extendedProps: { driver: { name: 'Ada' }, workload: { assigned: 9, capacity: 10, percentage } } } }).html;

        assert.true(label(20).includes('Ada'), 'the driver is named');
        assert.true(label(20).includes('#6366f1'), 'a light load is indigo');
        assert.true(label(75).includes('#f59e0b'), 'a busy driver is amber');
        assert.true(label(95).includes('#ef4444'), 'and one at capacity is red');

        assert.strictEqual(
            this.controller.renderResourceLabel({ resource: { title: 'Unassigned', extendedProps: {} } }),
            'Unassigned',
            'a row with no driver behind it falls back to its own title'
        );
    });

    test('the event tile shows the tracking number, status and destination', function (assert) {
        const { html } = this.controller.renderEventContent({
            event: {
                title: 'TRK111',
                extendedProps: { status: 'dispatched', order: { tracking: 'TRK111', pickupName: 'Depot', scheduledAtTime: '09:00', driver_assigned: { name: 'Ada' } } },
            },
        });

        assert.true(html.includes('TRK111'), 'the tracking number');
        assert.true(html.includes('Dispatched'), 'the status, capitalised');
        assert.true(html.includes('Ada'), 'the driver');
        assert.true(html.includes('Depot'), 'and where it is going');

        assert.strictEqual(this.controller.renderEventContent({ event: { display: 'background' } }), null, 'a shift block draws itself');
    });

    test('orders can be selected one at a time, all at once, or cleared', function (assert) {
        this.givenOrder({ id: 'o_1', status: 'created', public_id: 'ORD_1' });
        this.givenOrder({ id: 'o_2', status: 'created', public_id: 'ORD_2' });

        assert.false(this.controller.hasSelection, 'nothing is selected to start with');

        this.controller.toggleOrderSelection('o_1');
        assert.true(this.controller.isOrderSelected('o_1'));
        assert.true(this.controller.hasSelection);
        assert.deepEqual(
            this.controller.selectedOrders.map((o) => o.id),
            ['o_1']
        );

        this.controller.toggleOrderSelection('o_1');
        assert.false(this.controller.isOrderSelected('o_1'), 'clicking again unselects it');

        this.controller.selectAllOrders();
        assert.deepEqual([...this.controller.selectedOrderIds].sort(), ['o_1', 'o_2'], 'select-all takes what the sidebar is showing');

        this.controller.clearSelection();
        assert.false(this.controller.hasSelection);
    });

    test('typing in the search box is debounced', async function (assert) {
        this.controller.onSearchInput({ target: { value: 'alpha' } });
        assert.strictEqual(this.controller.searchQuery, '', 'the query does not land on the keystroke');

        // The task debounces with a bare `setTimeout`, which the settled-ness checks do not track.
        await waitUntil(() => this.controller.searchQuery === 'alpha', { timeout: 2000 });
        assert.strictEqual(this.controller.searchQuery, 'alpha', 'it lands once typing stops');

        this.controller.clearSearch();
        assert.strictEqual(this.controller.searchQuery, '', 'and the clear button empties it straight away');
    });

    test('the calendar view name follows the chosen range', function (assert) {
        this.controller.setCalendarApi(this.calendarApi);

        this.controller.setViewRange('week');
        assert.strictEqual(this.controller.currentCalendarView, 'resourceTimelineWeek');
        assert.deepEqual(this.calendarCalls.at(-1), ['setOption', 'view', 'resourceTimelineWeek'], 'and the calendar is told');

        this.controller.setViewRange('day');
        assert.strictEqual(this.controller.currentCalendarView, 'resourceTimelineDay');

        this.controller.setViewRange('fortnight');
        assert.strictEqual(this.controller.currentCalendarView, 'resourceTimelineDay', 'a range with no view falls back to the day');
    });

    test('stepping through the calendar keeps the controller in step with it', function (assert) {
        this.controller.setCalendarApi(this.calendarApi);

        this.controller.goToPrev();
        assert.deepEqual(this.controller.viewDate, new Date('2026-03-03T00:00:00Z'), 'the controller takes the date the calendar moved to');

        this.controller.goToNext();
        assert.deepEqual(this.controller.viewDate, new Date('2026-03-17T00:00:00Z'));

        this.controller.goToToday();
        assert.deepEqual(this.calendarCalls.at(-1), ['setOption', 'date', this.controller.viewDate], 'today is pushed the other way, to the calendar');
    });

    test('the calendar is optional — navigating before it mounts does nothing', function (assert) {
        const before = this.controller.viewDate;

        this.controller.goToPrev();
        this.controller.goToNext();
        assert.strictEqual(this.controller.viewDate, before, 'prev and next need the calendar to say where they landed');

        this.controller.setViewRange('week');
        assert.strictEqual(this.controller.currentCalendarView, 'resourceTimelineWeek', 'while the range is the controller’s own state');
    });

    test('the company timezone drives the calendar, and falls back to the browser', function (assert) {
        assert.strictEqual(this.controller.companyTimezone, 'Asia/Singapore');

        this.companyTimezone = undefined;
        assert.strictEqual(this.controller.companyTimezone, Intl.DateTimeFormat().resolvedOptions().timeZone, 'a company with no timezone set leaves the browser to decide');
    });

    test('the calendar is handed a full day and a 24-hour clock', function (assert) {
        assert.strictEqual(this.controller.calendarSlotMinTime, '00:00:00');
        assert.strictEqual(this.controller.calendarSlotMaxTime, '24:00:00');
        assert.deepEqual(this.controller.calendarHeaderToolbar, { start: '', center: 'title', end: '' }, 'only the title — the page header has the rest');
        assert.false(this.controller.calendarOptions.slotLabelFormat.hour12, 'times are shown on a 24-hour clock');
        assert.true(this.controller.calendarNow instanceof Date, 'and "now" is handed over as a date');
    });

    test('driver shifts are drawn behind the events', function (assert) {
        const withShift = this.store.push(this.store.normalize('driver', { uuid: 'd_1', name: 'Ada' }));
        withShift.currentShift = { start_time: '2026-03-10T08:00:00Z', end_time: '2026-03-10T17:00:00Z' };
        const withoutShift = this.store.push(this.store.normalize('driver', { uuid: 'd_2', name: 'Grace' }));
        this.controller.drivers = [withShift, withoutShift];

        assert.strictEqual(this.controller.backgroundEvents.length, 1, 'only the driver on shift gets a block');
        assert.strictEqual(this.controller.backgroundEvents[0].display, 'background');

        this.givenOrder({ id: 'o_1', status: 'created', public_id: 'ORD_1', scheduled_at: '2026-03-10T09:00:00Z' });
        assert.strictEqual(this.controller.allCalendarEvents.length, 2, 'the calendar gets the orders and the shifts together');
    });

    // -------------------------------------------------------------------------
    // Drag and drop
    // -------------------------------------------------------------------------

    /** A drag event carrying the dataTransfer the browser would supply. */
    function dragEvent(overrides = {}) {
        return {
            preventDefault() {
                this.defaultPrevented = true;
            },
            defaultPrevented: false,
            clientX: 320,
            clientY: 180,
            dataTransfer: {
                setData(key, value) {
                    this.data = [key, value];
                },
                data: null,
                effectAllowed: null,
                dropEffect: null,
            },
            ...overrides,
        };
    }

    test('dragging a card out of the sidebar remembers which order it was', function (assert) {
        const order = this.givenOrder({ id: 'o_1', status: 'created', public_id: 'ORD_1' });
        const event = dragEvent();

        this.controller.onSidebarDragStart(order, event);

        assert.deepEqual(event.dataTransfer.data, ['text/plain', 'o_1'], 'the id goes on the drag as a fallback');
        assert.strictEqual(event.dataTransfer.effectAllowed, 'move');
    });

    test('dragging over the timeline highlights it and follows the pointer', function (assert) {
        const event = dragEvent({ clientX: 500 });
        this.controller.onCalendarDragOver(event);

        assert.true(event.defaultPrevented, 'the default "no drop" behaviour is suppressed so a drop can fire');
        assert.strictEqual(event.dataTransfer.dropEffect, 'move');
        assert.strictEqual(this.timeline.dataset.draggingOver, 'true', 'the container is marked for the CSS highlight');

        const cursor = this.ecMain.querySelector('.ec-drop-cursor');
        assert.ok(cursor, 'a drop cursor is put on the timeline');

        // Dragging again moves the same cursor rather than adding another.
        this.controller.onCalendarDragOver(dragEvent({ clientX: 600 }));
        assert.strictEqual(this.ecMain.querySelectorAll('.ec-drop-cursor').length, 1, 'and it is moved, not duplicated');
    });

    test('leaving the timeline clears the highlight, but moving between its children does not', function (assert) {
        this.controller.onCalendarDragOver(dragEvent());
        assert.strictEqual(this.timeline.dataset.draggingOver, 'true');

        // relatedTarget inside the timeline means the pointer only moved to a child.
        this.controller.onCalendarDragLeave({ relatedTarget: this.ecMain });
        assert.strictEqual(this.timeline.dataset.draggingOver, 'true', 'moving onto a child is not leaving');

        this.controller.onCalendarDragLeave({ relatedTarget: document.body });
        assert.strictEqual(this.timeline.dataset.draggingOver, undefined, 'leaving for good clears the highlight');
        assert.notOk(this.ecMain.querySelector('.ec-drop-cursor'), 'and takes the cursor with it');
    });

    test('dropping an order on the timeline assigns it to that driver and time', async function (assert) {
        const order = this.givenOrder({ id: 'o_1', status: 'created', public_id: 'ORD_1' });
        this.controller.setCalendarApi(this.calendarApi);
        this.dropInfo = { date: new Date(2026, 2, 10, 14, 30), resource: { id: 'd_1' } };

        this.controller.onSidebarDragStart(order, dragEvent());
        await this.controller.onCalendarDrop(dragEvent());

        assert.strictEqual(this.assignments.length, 1, 'the order is assigned');
        const [assignment] = this.assignments;
        assert.strictEqual(assignment.order, order);
        assert.strictEqual(assignment.driverId, 'd_1', 'to the driver whose row it landed on');
        // 14:30 on screen in Asia/Singapore is 06:30 UTC.
        assert.strictEqual(assignment.scheduledAt.toISOString(), '2026-03-10T06:30:00.000Z', 'and to the wall-clock time it was dropped at, read as company time');
        assert.strictEqual(this.controller._orderRevision, 1, 'the sidebar is told to recompute');
        assert.strictEqual(this.timeline.dataset.draggingOver, undefined, 'the highlight is cleared');
    });

    test('a drop with nothing being dragged, or before the calendar mounts, does nothing', async function (assert) {
        await this.controller.onCalendarDrop(dragEvent());
        assert.deepEqual(this.assignments, [], 'no dragged order means no assignment');

        const order = this.givenOrder({ id: 'o_1', status: 'created', public_id: 'ORD_1' });
        this.controller.onSidebarDragStart(order, dragEvent());
        await this.controller.onCalendarDrop(dragEvent());
        assert.deepEqual(this.assignments, [], 'and neither does a drop before the calendar is there');
    });

    test('a drop the calendar cannot place is abandoned', async function (assert) {
        const order = this.givenOrder({ id: 'o_1', status: 'created', public_id: 'ORD_1' });
        this.controller.setCalendarApi(this.calendarApi);
        this.dropInfo = null;

        this.controller.onSidebarDragStart(order, dragEvent());
        await this.controller.onCalendarDrop(dragEvent());

        assert.deepEqual(this.assignments, [], 'a point outside the grid assigns nothing');
    });

    test('a drop onto no particular driver still schedules the order', async function (assert) {
        const order = this.givenOrder({ id: 'o_1', status: 'created', public_id: 'ORD_1' });
        this.controller.setCalendarApi(this.calendarApi);
        this.dropInfo = { date: null, resource: null };

        this.controller.onSidebarDragStart(order, dragEvent());
        await this.controller.onCalendarDrop(dragEvent());

        const [assignment] = this.assignments;
        assert.strictEqual(assignment.driverId, null, 'with no driver');
        assert.true(assignment.scheduledAt instanceof Date, 'and for now, when the calendar gives no date');
    });

    test('a drop the scheduler refuses leaves the sidebar alone', async function (assert) {
        const order = this.givenOrder({ id: 'o_1', status: 'created', public_id: 'ORD_1' });
        this.controller.setCalendarApi(this.calendarApi);
        this.dropInfo = { date: new Date(2026, 2, 10, 14, 30), resource: { id: 'd_1' } };
        this.assignResult = { error: new Error('nope'), hasConflict: false };

        this.controller.onSidebarDragStart(order, dragEvent());
        await this.controller.onCalendarDrop(dragEvent());

        assert.strictEqual(this.controller._orderRevision, 0, 'the order stays where it was');
    });

    test('a drop that clashes puts up the conflict modal', async function (assert) {
        const order = this.givenOrder({ id: 'o_1', status: 'created', public_id: 'ORD_1' });
        this.controller.setCalendarApi(this.calendarApi);
        this.dropInfo = { date: new Date(2026, 2, 10, 14, 30), resource: { id: 'd_1' } };
        this.assignResult = { error: null, hasConflict: true, conflicts: [{ id: 'o_other' }] };

        this.controller.onSidebarDragStart(order, dragEvent());
        await this.controller.onCalendarDrop(dragEvent());

        const modal = this.modals.at(-1);
        assert.ok(modal, 'the clash is shown to the dispatcher');
        assert.strictEqual(modal[1].order, order, 'naming the order that clashed');
    });

    // -------------------------------------------------------------------------
    // Rescheduling an event already on the board
    // -------------------------------------------------------------------------

    test('dragging an order to a new slot reschedules it', async function (assert) {
        const order = this.givenOrder({ id: 'o_1', status: 'created', public_id: 'ORD_1', scheduled_at: '2026-03-10T09:00:00Z' });
        let reverted = 0;

        await this.controller.rescheduleEventFromDrag({
            event: { id: 'o_1', start: new Date(2026, 2, 11, 8, 0), end: new Date(2026, 2, 11, 9, 0), resourceIds: ['d_2'], extendedProps: {} },
            revert: () => (reverted += 1),
        });

        assert.strictEqual(this.assignments.length, 1);
        assert.strictEqual(this.assignments[0].order, order);
        assert.strictEqual(this.assignments[0].driverId, 'd_2', 'onto the row it was dropped on');
        assert.strictEqual(reverted, 0, 'nothing is put back');
        assert.strictEqual(this.controller._orderRevision, 1);
    });

    test('a reschedule that clashes or fails is put back where it was', async function (assert) {
        this.givenOrder({ id: 'o_1', status: 'created', public_id: 'ORD_1', scheduled_at: '2026-03-10T09:00:00Z' });
        // An event drawn on the timeline always sits on a resource row, so `resourceIds` carries one.
        const info = () => ({
            event: { id: 'o_1', start: new Date(2026, 2, 11, 8, 0), end: new Date(2026, 2, 11, 9, 0), resourceIds: ['d_2'], extendedProps: {} },
            revert: () => (reverted += 1),
        });
        let reverted = 0;

        this.assignResult = { error: null, hasConflict: true, conflicts: [] };
        await this.controller.rescheduleEventFromDrag(info());
        assert.strictEqual(reverted, 1, 'a clash snaps the event back');
        assert.ok(this.modals.at(-1), 'and says why');

        this.assignResult = { error: new Error('nope'), hasConflict: false };
        await this.controller.rescheduleEventFromDrag(info());
        assert.strictEqual(reverted, 2, 'so does a refusal');
        assert.strictEqual(this.controller._orderRevision, 0, 'neither counts as a change');
    });

    test('an event for an order that is no longer loaded is ignored', async function (assert) {
        let reverted = 0;
        await this.controller.rescheduleEventFromDrag({
            event: { id: 'o_missing', start: new Date(), end: new Date(), resourceIds: [], extendedProps: {} },
            revert: () => (reverted += 1),
        });

        assert.deepEqual(this.assignments, [], 'nothing is assigned');
        assert.strictEqual(reverted, 0, 'and nothing is reverted — there is nothing to put back');
    });

    test('dragging a shift block moves the shift itself', async function (assert) {
        const saved = [];
        const scheduleItem = {
            props: {},
            set(key, value) {
                this.props[key] = value;
            },
            save() {
                saved.push({ ...this.props });
                return Promise.resolve();
            },
        };

        await this.controller.rescheduleEventFromDrag({
            event: { start: new Date(2026, 2, 11, 8, 0), end: new Date(2026, 2, 11, 17, 0), resourceIds: ['d_2'], extendedProps: { scheduleItem } },
            revert: () => assert.ok(false, 'a shift that saves is not reverted'),
        });

        assert.strictEqual(saved.length, 1, 'the shift is saved');
        assert.strictEqual(saved[0].assignee_uuid, 'd_2', 'against the driver it was dragged to');
        assert.strictEqual(saved[0].start_at.toISOString(), '2026-03-11T00:00:00.000Z', 'with 08:00 company time read back as UTC');
        assert.deepEqual(this.notified.at(-1), ['success', 'Shift updated successfully.'], 'and the dispatcher is told');
        assert.deepEqual(this.assignments, [], 'a shift never goes through order assignment');
    });

    test('a shift that will not save is reported and put back', async function (assert) {
        let reverted = 0;
        const scheduleItem = { set() {}, save: () => Promise.reject(new Error('server said no')) };

        await this.controller.rescheduleEventFromDrag({
            event: { start: new Date(2026, 2, 11, 8, 0), end: null, resourceIds: [], extendedProps: { scheduleItem } },
            revert: () => (reverted += 1),
        });

        assert.deepEqual(this.notified.at(-1), ['serverError', 'server said no']);
        assert.strictEqual(reverted, 1, 'and the block snaps back');
    });

    // -------------------------------------------------------------------------
    // Real-time updates
    // -------------------------------------------------------------------------

    test('the board listens to its company and to every driver on it', async function (assert) {
        this.controller.drivers = [
            this.store.push(this.store.normalize('driver', { uuid: 'd_1', name: 'Ada' })),
            this.store.push(this.store.normalize('driver', { uuid: 'd_2', name: 'Grace' })),
        ];
        this.owner.lookup('service:current-user').companyId = 'company_1';

        await this.controller.subscribeToRealTimeUpdates();

        assert.deepEqual(
            this.channels.map((c) => c.channel),
            ['company.company_1.orders', 'driver.d_1', 'driver.d_2'],
            'one company channel and one per driver'
        );

        // The handlers the socket was given are what actually push records in.
        this.channels[0].handler({ data: { id: 'o_socket', uuid: 'o_socket', status: 'created', public_id: 'ORD_S', meta: {} } });
        assert.ok(this.store.peekRecord('order', 'o_socket'), 'the company channel pushes orders through');

        this.channels[1].handler({ event: 'driver.updated', data: { id: 'd_3', uuid: 'd_3', name: 'Hopper' } });
        assert.ok(this.store.peekRecord('driver', 'd_3'), 'and a driver channel pushes that driver through');

        this.controller.unsubscribeFromRealTimeUpdates();
        assert.strictEqual(this.channelsClosed, 1, 'and they are all closed again on the way out');
    });

    test('with no company there is nothing to listen to', async function (assert) {
        this.companyTimezone = 'Asia/Singapore';
        this.owner.lookup('service:current-user').companyId = undefined;
        Object.defineProperty(this.owner.lookup('service:current-user'), 'company', { get: () => ({}), configurable: true });

        await this.controller.subscribeToRealTimeUpdates();
        assert.deepEqual(this.channels, [], 'no channels are opened');
    });

    test('an order pushed over the socket lands in the store', function (assert) {
        this.controller._handleOrderSocketEvent({ data: { id: 'o_socket', uuid: 'o_socket', status: 'created', public_id: 'ORD_S', meta: {} } });
        assert.ok(this.store.peekRecord('order', 'o_socket'), 'the order arrives without a page refresh');

        // Neither of these should throw.
        this.controller._handleOrderSocketEvent();
        this.controller._handleOrderSocketEvent({ data: {} });
        this.controller._handleOrderSocketEvent({ data: { id: 'o_bad', bogus: Symbol('unserialisable') } });
        assert.true(true, 'an event with no usable payload is dropped rather than thrown');
    });

    test('driver location pings are ignored, other driver events are not', function (assert) {
        this.controller._handleDriverSocketEvent({ event: 'driver.location_changed', data: { id: 'd_ping', uuid: 'd_ping', name: 'Ada' } });
        assert.notOk(this.store.peekRecord('driver', 'd_ping'), 'location pings are far too frequent to push');

        this.controller._handleDriverSocketEvent({ event: 'driver.updated', data: { id: 'd_1', uuid: 'd_1', name: 'Ada' } });
        assert.ok(this.store.peekRecord('driver', 'd_1'), 'but a real change is pushed');

        this.controller._handleDriverSocketEvent();
        assert.true(true, 'and an empty event is dropped');
    });

    test('undo and redo go straight to the scheduling service', function (assert) {
        assert.strictEqual(this.controller.undo(), 'undone');
        assert.strictEqual(this.controller.redo(), 'redone');
        assert.deepEqual(this.assignments, ['undo', 'redo']);
    });

    // -------------------------------------------------------------------------
    // Calendar event helpers
    // -------------------------------------------------------------------------

    test('an event can be removed by object, by JSON or by id', function (assert) {
        this.controller.setCalendarApi(this.calendarApi);

        assert.true(this.controller.removeEvent({ id: 'e_1' }), 'an object carrying an id');
        assert.true(this.controller.removeEvent('{"id":"e_2"}'), 'a JSON string');
        assert.true(this.controller.removeEvent('e_3'), 'or the id on its own');
        assert.deepEqual(
            this.calendarCalls.filter(([name]) => name === 'removeEventById').map(([, id]) => id),
            ['e_1', 'e_2', 'e_3']
        );

        assert.false(this.controller.removeEvent(42), 'anything else is refused');
        assert.false(this.controller.removeEvent({ id: 7 }), 'including an object whose id is not a string');
    });

    test('an event can be looked up by JSON, by id, or handed back as it came', function (assert) {
        this.controller.setCalendarApi(this.calendarApi);
        this.calendarEventsById = { e_1: { id: 'e_1', title: 'ORD_1' } };

        assert.deepEqual(this.controller.getEvent('{"id":"e_1"}'), { id: 'e_1', title: 'ORD_1' }, 'from JSON');
        assert.deepEqual(this.controller.getEvent('e_1'), { id: 'e_1', title: 'ORD_1' }, 'from an id');

        const alreadyAnEvent = { id: 'e_9', title: 'ORD_9' };
        assert.strictEqual(this.controller.getEvent(alreadyAnEvent), alreadyAnEvent, 'an event object is returned unchanged');
    });

    test('setting a property on an event updates it on the calendar', function (assert) {
        this.controller.setCalendarApi(this.calendarApi);
        this.calendarEventsById = { e_1: { id: 'e_1', title: 'ORD_1' } };

        assert.true(this.controller.setEventProperty('e_1', 'title', 'ORD_1 (rescheduled)'));
        assert.deepEqual(this.calendarCalls.at(-1), ['updateEvent', { id: 'e_1', title: 'ORD_1 (rescheduled)' }], 'the whole event goes back with the change applied');

        assert.false(this.controller.setEventProperty('e_missing', 'title', 'nothing'), 'an event the calendar does not have cannot be changed');
    });

    test('a wall-clock time is read back as an instant in the company timezone', function (assert) {
        // 22:30 on screen in Asia/Singapore (UTC+8) is 14:30 UTC.
        const result = this.controller._reinterpretDateInTimezone(new Date(2026, 3, 6, 22, 30, 0), 'Asia/Singapore');
        assert.strictEqual(result.toISOString(), '2026-04-06T14:30:00.000Z');

        // A timezone Intl cannot resolve leaves the date as it was rather than throwing.
        const original = new Date(2026, 3, 6, 22, 30, 0);
        assert.strictEqual(this.controller._reinterpretDateInTimezone(original, 'Not/AZone'), original, 'an unusable timezone is survived');
    });
});
