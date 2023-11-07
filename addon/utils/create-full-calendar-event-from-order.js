export default function createFullCalendarEventFromOrder(order) {
    return {
        id: order.id,
        title: `${order.scheduledAtTime} - ${order.public_id}`,
        start: order.scheduled_at,
        allDay: true,
    };
}
