import serializeModel from '@fleetbase/ember-core/utils/serialize-model';
import serializeArray from '@fleetbase/ember-core/utils/serialize-model-array';

function normalizeCustomerType(order) {
    if (order.customer_type) {
        return order.customer_type;
    }

    const customerType = order.customer?.customer_type;
    return customerType ? `fleet-ops:${customerType}` : null;
}

function normalizeFacilitatorType(order) {
    if (order.facilitator_type) {
        return order.facilitator_type;
    }

    const facilitatorType = order.facilitator?.facilitator_type;
    return facilitatorType ? `fleet-ops:${facilitatorType}` : null;
}

function createDraftPlace(store, place) {
    if (!place) {
        return null;
    }

    if (typeof place.get === 'function') {
        return place;
    }

    return store.createRecord('place', {
        ...place,
        id: place.id ?? place.public_id ?? undefined,
    });
}

function createDraftWaypoint(store, waypoint) {
    return store.createRecord('waypoint', {
        ...waypoint,
        id: waypoint.id ?? waypoint.public_id ?? undefined,
        place: createDraftPlace(store, waypoint.place),
    });
}

function createDraftEntity(store, entity) {
    return store.createRecord('entity', {
        ...entity,
        id: entity.id ?? entity.public_id ?? undefined,
    });
}

export function createRecurringDraftOrder(store, source = {}) {
    const templatePayload = source.template_payload ?? source.payload ?? {};
    const templateOrderMeta = source.template_order_meta ?? {};
    const templateEntities = source.template_entities ?? templatePayload.entities ?? [];

    const order = store.createRecord('order', {
        customer: source.customer ?? null,
        customer_uuid: source.customer_uuid ?? source.customer?.id ?? null,
        customer_type: source.customer_type ?? normalizeCustomerType(source),
        facilitator: source.facilitator ?? null,
        facilitator_uuid: source.facilitator_uuid ?? source.facilitator?.id ?? null,
        facilitator_type: source.facilitator_type ?? normalizeFacilitatorType(source),
        order_config: source.order_config ?? source.orderConfig ?? null,
        order_config_uuid: source.order_config_uuid ?? source.order_config?.id ?? null,
        driver_assigned: source.driver_assigned ?? source.driverAssigned ?? null,
        driver_assigned_uuid: source.driver_assigned_uuid ?? source.driver_assigned?.id ?? null,
        vehicle_assigned: source.vehicle_assigned ?? source.vehicleAssigned ?? null,
        vehicle_assigned_uuid: source.vehicle_assigned_uuid ?? source.vehicle_assigned?.id ?? null,
        internal_id: source.internal_id ?? templateOrderMeta.internal_id ?? null,
        scheduled_at: source.scheduled_at ?? source.starts_at ?? new Date(),
        pod_method: source.pod_method ?? templateOrderMeta.pod_method ?? null,
        pod_required: source.pod_required ?? templateOrderMeta.pod_required ?? false,
        adhoc: source.adhoc ?? templateOrderMeta.adhoc ?? false,
        adhoc_distance: source.adhoc_distance ?? templateOrderMeta.adhoc_distance ?? null,
        notes: source.notes ?? templateOrderMeta.notes ?? null,
        type: source.type ?? templateOrderMeta.type ?? templatePayload.type ?? null,
        meta: source.meta ?? templateOrderMeta.meta ?? {},
        required_skills: source.required_skills ?? templateOrderMeta.required_skills ?? [],
        orchestrator_priority: source.orchestrator_priority ?? templateOrderMeta.orchestrator_priority ?? 50,
        time_window_start: source.time_window_start ?? templateOrderMeta.time_window_start ?? null,
        time_window_end: source.time_window_end ?? templateOrderMeta.time_window_end ?? null,
        payload: store.createRecord('payload', {
            pickup: createDraftPlace(store, templatePayload.pickup),
            dropoff: createDraftPlace(store, templatePayload.dropoff),
            return: createDraftPlace(store, templatePayload.return),
            type: templatePayload.type ?? source.type ?? templateOrderMeta.type ?? null,
            payment_method: templatePayload.payment_method ?? null,
            cod_amount: templatePayload.cod_amount ?? null,
            cod_currency: templatePayload.cod_currency ?? null,
            cod_payment_method: templatePayload.cod_payment_method ?? null,
            meta: templatePayload.meta ?? {},
        }),
    });

    (templatePayload.waypoints ?? []).forEach((waypoint) => {
        order.payload.waypoints.pushObject(createDraftWaypoint(store, waypoint));
    });

    (templateEntities ?? []).forEach((entity) => {
        order.payload.entities.pushObject(createDraftEntity(store, entity));
    });

    return order;
}

export function serializeRecurringDraftOrder(order, serviceRateUuid = null) {
    const payload = order.payload;

    return {
        internal_id: order.internal_id ?? null,
        customer_uuid: order.customer?.id ?? order.customer_uuid ?? null,
        customer_type: normalizeCustomerType(order),
        facilitator_uuid: order.facilitator?.id ?? order.facilitator_uuid ?? null,
        facilitator_type: normalizeFacilitatorType(order),
        order_config_uuid: order.order_config?.id ?? order.order_config_uuid ?? null,
        driver_assigned_uuid: order.driver_assigned?.id ?? order.driver_assigned_uuid ?? null,
        vehicle_assigned_uuid: order.vehicle_assigned?.id ?? order.vehicle_assigned_uuid ?? null,
        service_rate_uuid: serviceRateUuid ?? null,
        type: order.type ?? payload.type ?? null,
        pod_method: order.pod_method ?? null,
        pod_required: Boolean(order.pod_required),
        adhoc: Boolean(order.adhoc),
        adhoc_distance: order.adhoc_distance ?? null,
        notes: order.notes ?? null,
        meta: order.meta ?? {},
        required_skills: order.required_skills ?? [],
        orchestrator_priority: order.orchestrator_priority ?? 50,
        time_window_start: order.time_window_start ?? null,
        time_window_end: order.time_window_end ?? null,
        payload: {
            pickup: serializeModel(payload.pickup),
            dropoff: serializeModel(payload.dropoff),
            return: serializeModel(payload.return),
            waypoints: serializeArray(payload.waypoints),
            entities: serializeArray(payload.entities),
            type: payload.type ?? order.type ?? null,
            payment_method: payload.payment_method ?? null,
            cod_amount: payload.cod_amount ?? null,
            cod_currency: payload.cod_currency ?? null,
            cod_payment_method: payload.cod_payment_method ?? null,
            meta: payload.meta ?? {},
        },
    };
}
