import { get } from "@ember/object";
import { addMinutes } from "date-fns";

const STATUS_COLORS = {
    created: "#6366f1",
    dispatched: "#3b82f6",
    active: "#22c55e",
    completed: "#9ca3af",
    cancelled: "#ef4444",
    expired: "#f97316",
};

export function createOrderEventTitle(order) {
    return get(order, "tracking") ?? get(order, "public_id");
}

export function createOrderEventDescription(order) {
    const scheduledAtTime = get(order, "scheduledAtTime");
    const driverAssignedName = get(order, "driver_assigned.name");
    const vehicleAssignedName = get(order, "driver_assigned.vehicle_name");
    const destination = get(order, "pickupName");
    const parts = [];
    if (driverAssignedName) {
        parts.push(driverAssignedName + " @ " + scheduledAtTime);
        if (vehicleAssignedName) parts.push(vehicleAssignedName);
        if (destination) parts.push("to " + destination);
    } else {
        if (scheduledAtTime) parts.push(scheduledAtTime);
        if (destination) parts.push("to " + destination);
        if (vehicleAssignedName) parts.push(vehicleAssignedName);
    }
    return parts.filter(Boolean).join('\n');
}

export default function createFullCalendarEventFromOrder(order) {
    const scheduledAt = get(order, "scheduled_at");
    const duration = get(order, "estimated_duration") ?? 60;
    const start = scheduledAt ? new Date(scheduledAt) : null;
    const end = start ? addMinutes(start, duration) : null;
    const status = get(order, "status") ?? "created";
    const color = STATUS_COLORS[status] ?? STATUS_COLORS.created;
    return {
        id: get(order, "id"),
        resourceId: get(order, "driver_uuid") ?? null,
        title: createOrderEventTitle(order),
        description: createOrderEventDescription(order),
        start,
        end,
        display: "block",
        backgroundColor: color,
        borderColor: color,
        textColor: "#ffffff",
        extendedProps: { order, status, type: "order" },
    };
}
