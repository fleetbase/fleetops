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

        /** A stand-in for the EventCalendar instance the template hands back on mount. */
        this.calendarCalls = [];
        this.calendarOptions = { date: new Date('2026-03-10T00:00:00Z') };
        this.calendarApi = {
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
});
