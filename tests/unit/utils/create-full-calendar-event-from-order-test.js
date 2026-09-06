import { module, test } from 'qunit';
import createFullCalendarEventFromOrder, { createOrderEventTitle, createOrderEventDescription } from '@fleetbase/fleetops-engine/utils/create-full-calendar-event-from-order';
import toCalendarDate from '@fleetbase/fleetops-engine/utils/to-calendar-date';

const TIMEZONE = 'Asia/Singapore';

module('Unit | Utility | create-full-calendar-event-from-order', function () {
    test('the title prefers the tracking number over the public id', function (assert) {
        assert.strictEqual(createOrderEventTitle({ tracking: 'TRK-1', public_id: 'order_1' }), 'TRK-1');
        assert.strictEqual(createOrderEventTitle({ public_id: 'order_1' }), 'order_1');
    });

    test('the description leads with the driver when one is assigned', function (assert) {
        assert.strictEqual(
            createOrderEventDescription({ scheduledAtTime: '10:00', driver_assigned: { name: 'Ada', vehicle_name: 'Van' }, pickupName: 'Depot' }),
            'Ada @ 10:00\nVan\nto Depot'
        );
        assert.strictEqual(createOrderEventDescription({ scheduledAtTime: '10:00', driver_assigned: { name: 'Ada' } }), 'Ada @ 10:00');
    });

    test('the description lists the time, destination and vehicle without a driver', function (assert) {
        assert.strictEqual(createOrderEventDescription({ scheduledAtTime: '10:00', pickupName: 'Depot', driver_assigned: { vehicle_name: 'Van' } }), '10:00\nto Depot\nVan');
        assert.strictEqual(createOrderEventDescription({ pickupName: 'Depot' }), 'to Depot');
        assert.strictEqual(createOrderEventDescription({}), '');
    });

    test('it builds a calendar event in the company timezone', function (assert) {
        const order = {
            id: 'order_1',
            public_id: 'ORD-1',
            scheduled_at: '2026-04-06T14:30:00Z',
            estimated_duration: 90,
            status: 'active',
            driver_assigned_uuid: 'driver_1',
            driver_assigned: { name: 'Ada' },
            scheduledAtTime: '22:30',
        };
        const startUtc = new Date(order.scheduled_at);

        const event = createFullCalendarEventFromOrder(order, TIMEZONE);

        assert.strictEqual(event.id, 'order_1');
        assert.strictEqual(event.resourceId, 'driver_1');
        assert.strictEqual(event.title, 'ORD-1');
        assert.strictEqual(event.description, 'Ada @ 22:30');
        assert.strictEqual(event.start.getTime(), toCalendarDate(startUtc, TIMEZONE).getTime());
        assert.strictEqual(event.end.getTime(), toCalendarDate(new Date(startUtc.getTime() + 90 * 60000), TIMEZONE).getTime());
        assert.strictEqual(event.display, 'block');
        assert.strictEqual(event.backgroundColor, '#22c55e');
        assert.strictEqual(event.borderColor, '#22c55e');
        assert.strictEqual(event.textColor, '#ffffff');
        assert.deepEqual(event.extendedProps, { order, status: 'active', type: 'order' });
    });

    test('it defaults the duration, status, colour and resource', function (assert) {
        const scheduled = createFullCalendarEventFromOrder({ id: 'order_2', scheduled_at: '2026-04-06T14:30:00Z' }, TIMEZONE);
        const startUtc = new Date('2026-04-06T14:30:00Z');

        assert.strictEqual(scheduled.resourceId, null);
        assert.strictEqual(scheduled.end.getTime(), toCalendarDate(new Date(startUtc.getTime() + 60 * 60000), TIMEZONE).getTime(), 'an hour when no duration is estimated');
        assert.strictEqual(scheduled.backgroundColor, '#6366f1', 'a missing status counts as created');
        assert.strictEqual(scheduled.extendedProps.status, 'created');

        const unknown = createFullCalendarEventFromOrder({ id: 'order_3', status: 'weird' }, TIMEZONE);
        assert.strictEqual(unknown.start, null, 'an unscheduled order has no start');
        assert.strictEqual(unknown.end, null);
        assert.strictEqual(unknown.backgroundColor, '#6366f1', 'an unknown status takes the created colour');
        assert.strictEqual(unknown.extendedProps.status, 'weird');
    });
});
