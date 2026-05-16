import serializeModel from '@fleetbase/ember-core/utils/serialize-model';
import serializeArray from '@fleetbase/ember-core/utils/serialize-model-array';

const CHILD_RECORD_IDENTITY_KEYS = ['id', 'uuid', 'public_id', 'created_at', 'updated_at', 'deleted_at'];

const ENTITY_LINKAGE_KEYS = [
    ...CHILD_RECORD_IDENTITY_KEYS,
    'payload_uuid',
    'company_uuid',
    'customer_uuid',
    'supplier_uuid',
    'tracking_number_uuid',
    'driver_assigned_uuid',
    'photo_uuid',
    'tracking',
    'trackingNumber',
    'barcode',
    'qr_code',
    'slug',
];

const WAYPOINT_LINKAGE_KEYS = [...CHILD_RECORD_IDENTITY_KEYS, 'waypoint_uuid', 'waypoint_public_id', 'tracking_number_uuid', 'tracking', 'status', 'status_code', 'complete'];

function toPlainObject(record) {
    if (!record) {
        return {};
    }

    if (typeof record.serialize === 'function') {
        return record.serialize();
    }

    return { ...record };
}

function copyWithout(record, keys = []) {
    const copy = toPlainObject(record);

    keys.forEach((key) => {
        delete copy[key];
    });

    return copy;
}

function relationshipValue(record, relationshipName) {
    if (!record) {
        return null;
    }

    if (typeof record.belongsTo === 'function') {
        try {
            return record.belongsTo(relationshipName).value();
        } catch {
            return null;
        }
    }

    return record[relationshipName] ?? null;
}

function normalizeCustomerType(order) {
    if (order.customer_type) {
        return order.customer_type;
    }

    const customerType = relationshipValue(order, 'customer')?.customer_type;
    return customerType ? `fleet-ops:${customerType}` : null;
}

function normalizeFacilitatorType(order) {
    if (order.facilitator_type) {
        return order.facilitator_type;
    }

    const facilitatorType = relationshipValue(order, 'facilitator')?.facilitator_type;
    return facilitatorType ? `fleet-ops:${facilitatorType}` : null;
}

function createDraftPlace(store, place) {
    if (!place) {
        return null;
    }

    if (typeof place.get === 'function') {
        return place;
    }

    const placeId = place.id ?? place.public_id;
    const loadedPlace = placeId ? store.peekRecord('place', placeId) : null;

    if (loadedPlace) {
        return loadedPlace;
    }

    return store.createRecord('place', {
        ...place,
        id: placeId ?? undefined,
    });
}

function createDraftWaypoint(store, waypoint) {
    const waypointAttrs = copyWithout(waypoint, WAYPOINT_LINKAGE_KEYS);

    return store.createRecord('waypoint', {
        ...waypointAttrs,
        place: createDraftPlace(store, waypoint.place),
    });
}

function createDraftEntity(store, entity) {
    return store.createRecord('entity', copyWithout(entity, ENTITY_LINKAGE_KEYS));
}

export function createRecurringDraftOrder(store, source = {}) {
    const templatePayload = source.template_payload ?? source.payload ?? {};
    const templateOrderMeta = source.template_order_meta ?? {};
    const templateEntities = source.template_entities ?? templatePayload.entities ?? [];
    const customer = relationshipValue(source, 'customer');
    const facilitator = relationshipValue(source, 'facilitator');
    const orderConfig = relationshipValue(source, 'order_config') ?? relationshipValue(source, 'orderConfig');
    const driverAssigned = relationshipValue(source, 'driver_assigned') ?? relationshipValue(source, 'driverAssigned');
    const vehicleAssigned = relationshipValue(source, 'vehicle_assigned') ?? relationshipValue(source, 'vehicleAssigned');

    const order = store.createRecord('order', {
        customer,
        customer_uuid: source.customer_uuid ?? customer?.id ?? null,
        customer_type: source.customer_type ?? normalizeCustomerType(source),
        facilitator,
        facilitator_uuid: source.facilitator_uuid ?? facilitator?.id ?? null,
        facilitator_type: source.facilitator_type ?? normalizeFacilitatorType(source),
        order_config: orderConfig,
        order_config_uuid: source.order_config_uuid ?? orderConfig?.id ?? null,
        driver_assigned: driverAssigned,
        driver_assigned_uuid: source.driver_assigned_uuid ?? driverAssigned?.id ?? null,
        vehicle_assigned: vehicleAssigned,
        vehicle_assigned_uuid: source.vehicle_assigned_uuid ?? vehicleAssigned?.id ?? null,
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
            pickup: serializeTemplatePlace(payload.pickup),
            dropoff: serializeTemplatePlace(payload.dropoff),
            return: serializeTemplatePlace(payload.return),
            waypoints: serializeArray(payload.waypoints).map(serializeTemplateWaypoint),
            entities: serializeArray(payload.entities).map(serializeTemplateEntity),
            type: payload.type ?? order.type ?? null,
            payment_method: payload.payment_method ?? null,
            cod_amount: payload.cod_amount ?? null,
            cod_currency: payload.cod_currency ?? null,
            cod_payment_method: payload.cod_payment_method ?? null,
            meta: payload.meta ?? {},
        },
    };
}

function serializeTemplatePlace(place) {
    const serialized = serializeModel(place);

    if (!serialized) {
        return null;
    }

    return {
        uuid: serialized.uuid ?? serialized.id ?? null,
        public_id: serialized.public_id ?? null,
        name: serialized.name ?? null,
        phone: serialized.phone ?? null,
        type: serialized.type ?? 'place',
        address: serialized.address ?? null,
        street1: serialized.street1 ?? null,
        street2: serialized.street2 ?? null,
        city: serialized.city ?? null,
        province: serialized.province ?? null,
        postal_code: serialized.postal_code ?? null,
        neighborhood: serialized.neighborhood ?? null,
        district: serialized.district ?? null,
        building: serialized.building ?? null,
        security_access_code: serialized.security_access_code ?? null,
        country: serialized.country ?? null,
        location: serialized.location ?? null,
        meta: serialized.meta ?? {},
    };
}

function serializeTemplateWaypoint(waypoint) {
    return {
        place: serializeTemplatePlace(waypoint.place ?? waypoint),
        type: waypoint.type ?? 'dropoff',
        order: waypoint.order ?? null,
        customer_uuid: waypoint.customer_uuid ?? null,
        customer_type: waypoint.customer_type ?? null,
        time_window_start: waypoint.time_window_start ?? null,
        time_window_end: waypoint.time_window_end ?? null,
        service_time: waypoint.service_time ?? null,
        notes: waypoint.notes ?? null,
        pod_method: waypoint.pod_method ?? null,
        pod_required: Boolean(waypoint.pod_required),
    };
}

function serializeTemplateEntity(entity) {
    return {
        internal_id: entity.internal_id ?? null,
        destination_uuid: entity.destination_uuid ?? entity.destination?.id ?? null,
        name: entity.name ?? null,
        type: entity.type ?? 'entity',
        description: entity.description ?? null,
        photo_url: entity.photo_url ?? null,
        currency: entity.currency ?? null,
        weight: entity.weight ?? null,
        weight_unit: entity.weight_unit ?? null,
        length: entity.length ?? null,
        width: entity.width ?? null,
        height: entity.height ?? null,
        dimensions_unit: entity.dimensions_unit ?? null,
        declared_value: entity.declared_value ?? null,
        sku: entity.sku ?? null,
        price: entity.price ?? null,
        sale_price: entity.sale_price ?? null,
        meta: entity.meta ?? {},
    };
}
