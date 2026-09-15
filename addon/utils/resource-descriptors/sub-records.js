import { get } from '@ember/object';
import { first, present, relation, icon, badge, badges, fact, relatedFact, money, join, parentOpener } from './helpers';

/**
 * Records that only make sense through a parent: connections, join rows,
 * quotes, fees, stops, waypoints, positions, tracking rows. Each gets an
 * icon tile, a dot only when it carries a status, a few facts, and either
 * opens its parent or nothing.
 */
export default function buildSubRecordDescriptors(owner) {
    return [
        {
            key: 'asset-connection',
            labelKey: 'resource.asset-connection',
            icon: 'link',
            modelNames: ['asset-connection'],
            polymorphicTypes: ['fleet-ops:asset-connection', 'Fleetbase\\FleetOps\\Models\\AssetConnection'],
            title: (connection) => join([first(connection, 'vehicle.displayName', 'vehicle.display_name'), first(connection, 'trailer.displayName', 'trailer.display_name')], ' ⇄ ') ?? first(connection, 'relationship_type', 'public_id'),
            identifier: (connection) => first(connection, 'relationship_type', 'source'),
            image: () => icon('link'),
            status: (connection) => (typeof get(connection, 'active') === 'boolean' ? (get(connection, 'active') ? 'active' : 'inactive') : undefined),
            facts: (connection) => [
                relatedFact('vehicle', relation(owner, connection, 'vehicle'), 'vehicle', null),
                relatedFact('trailer', relation(owner, connection, 'trailer'), 'trailer', null),
                fact('relationship', first(connection, 'relationship_type'), { format: 'humanize' }),
                fact('connected', get(connection, 'connected_at'), { format: 'date' }),
                fact('disconnected', get(connection, 'disconnected_at'), { format: 'date' }),
            ],
            open: parentOpener(owner, 'trailer', 'trailer'),
        },
        {
            key: 'fleet-driver',
            labelKey: 'resource.fleet-driver',
            icon: 'id-card',
            modelNames: ['fleet-driver'],
            title: (row) => first(row, 'driver.name', 'driver_name', 'fleet.name'),
            identifier: (row) => first(row, 'fleet.name', 'fleet_name'),
            image: () => icon('id-card'),
            facts: (row) => [relatedFact('driver', relation(owner, row, 'driver'), 'driver', null), relatedFact('fleet', relation(owner, row, 'fleet'), 'fleet', null), fact('created', get(row, 'created_at'), { format: 'date' })],
            open: parentOpener(owner, 'driver', 'driver'),
        },
        {
            key: 'inspection-item-result',
            labelKey: 'resource.inspection-item-result',
            icon: 'square-check',
            modelNames: ['inspection-item-result'],
            statusTones: { passed: 'text-green-500', failed: 'text-red-500', skipped: 'text-gray-400' },
            title: (result) => first(result, 'label', 'item_key'),
            identifier: (result) => first(result, 'category'),
            image: () => icon('square-check'),
            status: (result) => (typeof get(result, 'passed') === 'boolean' ? (get(result, 'passed') ? 'passed' : 'failed') : first(result, 'status')),
            facts: (result) => [fact('category', first(result, 'category'), { format: 'humanize' }), fact('status', first(result, 'status'), { format: 'humanize' }), fact('severity', first(result, 'severity'), { format: 'humanize' }), fact('comments', first(result, 'comments'))],
            open: parentOpener(owner, 'submission', 'inspection-submission'),
        },
        {
            key: 'manifest-stop',
            labelKey: 'resource.manifest-stop',
            icon: 'map-pin',
            modelNames: ['manifest-stop'],
            statusTones: { pending: 'text-gray-400', arrived: 'text-yellow-500', completed: 'text-green-500', skipped: 'text-red-500' },
            title: (stop) => first(stop, 'stopLabel', 'place.address', 'place.name') ?? (present(get(stop, 'sequence')) ? `Stop ${get(stop, 'sequence')}` : first(stop, 'public_id')),
            identifier: (stop) => first(stop, 'statusLabel', 'status'),
            image: () => icon('map-pin'),
            status: (stop) => first(stop, 'status'),
            facts: (stop) => [relatedFact('order', relation(owner, stop, 'order'), 'order', null), relatedFact('place', relation(owner, stop, 'place'), 'place', null), fact('sequence', get(stop, 'sequence')), fact('eta', get(stop, 'estimated_arrival'), { format: 'date' }), fact('arrived', get(stop, 'actual_arrival'), { format: 'date' })],
            open: parentOpener(owner, 'order', 'order'),
        },
        {
            key: 'payload',
            labelKey: 'resource.payload',
            icon: 'boxes-stacked',
            modelNames: ['payload'],
            title: (payload) => first(payload, 'public_id'),
            identifier: (payload) => (present(get(payload, 'entities_count')) ? `${get(payload, 'entities_count')} entities` : null),
            image: () => icon('boxes-stacked'),
            facts: (payload) => [relatedFact('pickup', relation(owner, payload, 'pickup'), 'place', null), relatedFact('dropoff', relation(owner, payload, 'dropoff'), 'place', null), fact('entities', get(payload, 'entities_count')), fact('waypoints', get(payload, 'waypoints_count')), fact('cod', money(get(payload, 'cod_amount'), get(payload, 'cod_currency')))],
        },
        {
            key: 'position',
            labelKey: 'resource.position',
            icon: 'location-crosshairs',
            modelNames: ['position'],
            title: (position) => first(position, 'positionString') ?? (present(get(position, 'latitude')) ? `${get(position, 'latitude')}, ${get(position, 'longitude')}` : first(position, 'public_id')),
            identifier: (position) => (get(position, 'created_at') ? new Date(get(position, 'created_at')).toLocaleString() : null),
            image: () => icon('location-crosshairs'),
            facts: (position) => [fact('speed', get(position, 'speed')), fact('heading', get(position, 'heading')), fact('altitude', get(position, 'altitude')), fact('recorded', get(position, 'created_at'), { format: 'date' })],
        },
        {
            key: 'purchase-rate',
            labelKey: 'resource.purchase-rate',
            icon: 'receipt',
            modelNames: ['purchase-rate'],
            title: (rate) => first(rate, 'service_quote.service_rate_name', 'public_id', 'status'),
            identifier: (rate) => first(rate, 'status'),
            image: () => icon('receipt'),
            status: (rate) => first(rate, 'status'),
            facts: (rate) => [relatedFact('service-quote', relation(owner, rate, 'service_quote'), 'service-quote', null), fact('status', first(rate, 'status'), { format: 'humanize' }), fact('created', get(rate, 'created_at'), { format: 'date' })],
        },
        {
            key: 'route',
            labelKey: 'resource.route',
            icon: 'route',
            modelNames: ['route'],
            title: (route) => first(route, 'order_public_id', 'order_internal_id'),
            identifier: (route) => first(route, 'order_status'),
            image: () => icon('route'),
            status: (route) => first(route, 'order_status'),
            facts: (route) => [fact('order', first(route, 'order_public_id')), fact('status', first(route, 'order_status'), { format: 'humanize' }), fact('created', get(route, 'created_at'), { format: 'date' })],
            open: async (route, context) => {
                const registry = owner.lookup('service:resource-registry');
                const store = owner.lookup('service:store');
                const id = get(route, 'order_uuid');

                if (!registry || !store || !id) {
                    return false;
                }

                const order = store.peekRecord('order', id) ?? (await store.findRecord('order', id));

                return order ? registry.open(order, { ...context, resourceType: 'order' }) : false;
            },
        },
        {
            key: 'service-quote',
            labelKey: 'resource.service-quote',
            icon: 'file-invoice',
            modelNames: ['service-quote'],
            title: (quote) => first(quote, 'service_rate_name', 'public_id'),
            identifier: (quote) => money(get(quote, 'amount'), get(quote, 'currency')),
            image: () => icon('file-invoice'),
            facts: (quote) => [fact('service-rate', first(quote, 'service_rate_name')), fact('amount', money(get(quote, 'amount'), get(quote, 'currency'))), fact('request', first(quote, 'request_id')), fact('expires', get(quote, 'expired_at'), { format: 'date' })],
        },
        {
            key: 'service-quote-item',
            labelKey: 'resource.service-quote-item',
            icon: 'list',
            modelNames: ['service-quote-item'],
            title: (item) => first(item, 'details', 'code', 'public_id'),
            identifier: (item) => money(get(item, 'amount'), get(item, 'currency')),
            image: () => icon('list'),
            facts: (item) => [fact('code', first(item, 'code')), fact('amount', money(get(item, 'amount'), get(item, 'currency'))), fact('details', first(item, 'details'))],
        },
        {
            key: 'service-rate-fee',
            labelKey: 'resource.service-rate-fee',
            icon: 'coins',
            modelNames: ['service-rate-fee'],
            title: (fee) => first(fee, 'label', 'geography_type') ?? join([get(fee, 'distance'), first(fee, 'distance_unit')]),
            identifier: (fee) => money(get(fee, 'fee'), get(fee, 'currency')),
            image: () => icon('coins'),
            facts: (fee) => [fact('fee', money(get(fee, 'fee'), get(fee, 'currency'))), fact('distance', join([get(fee, 'distance'), first(fee, 'distance_unit')])), relatedFact('service-area', relation(owner, fee, 'service_area'), 'service-area', null), relatedFact('zone', relation(owner, fee, 'zone'), 'zone', null), fact('fallback', typeof get(fee, 'is_fallback') === 'boolean' ? get(fee, 'is_fallback') : null)],
        },
        {
            key: 'service-rate-parcel-fee',
            labelKey: 'resource.service-rate-parcel-fee',
            icon: 'box',
            modelNames: ['service-rate-parcel-fee'],
            title: (fee) => first(fee, 'size') ?? join([get(fee, 'length'), get(fee, 'width'), get(fee, 'height')], ' × '),
            identifier: (fee) => money(get(fee, 'fee'), get(fee, 'currency')),
            image: () => icon('box'),
            facts: (fee) => [fact('fee', money(get(fee, 'fee'), get(fee, 'currency'))), fact('dimensions', join([join([get(fee, 'length'), get(fee, 'width'), get(fee, 'height')], ' × '), first(fee, 'dimensions_unit')])), fact('weight', join([get(fee, 'weight'), first(fee, 'weight_unit')]))],
        },
        {
            key: 'tracking-number',
            labelKey: 'resource.tracking-number',
            icon: 'barcode',
            modelNames: ['tracking-number'],
            title: (tracking) => first(tracking, 'tracking_number'),
            identifier: (tracking) => first(tracking, 'region', 'type'),
            image: (tracking) => {
                const qr = get(tracking, 'qr_code');
                const barcode = get(tracking, 'barcode');

                if (present(qr)) {
                    return { url: `data:image/png;base64,${qr}`, shape: 'square' };
                }

                if (present(barcode)) {
                    return { url: `data:image/png;base64,${barcode}`, shape: 'square' };
                }

                return icon('barcode');
            },
            status: (tracking) => first(tracking, 'last_status'),
            facts: (tracking) => [fact('tracking', first(tracking, 'tracking_number')), fact('last-status', first(tracking, 'last_status'), { format: 'humanize' }), fact('region', first(tracking, 'region')), fact('type', first(tracking, 'type'), { format: 'humanize' })],
        },
        {
            key: 'tracking-status',
            labelKey: 'resource.tracking-status',
            icon: 'clock-rotate-left',
            modelNames: ['tracking-status'],
            title: (status) => first(status, 'status', 'code'),
            identifier: (status) => first(status, 'code'),
            image: () => icon('clock-rotate-left'),
            status: (status) => first(status, 'status'),
            facts: (status) => [fact('details', first(status, 'details')), fact('location', join([first(status, 'city'), first(status, 'province'), first(status, 'country')], ', ')), fact('recorded', get(status, 'created_at'), { format: 'date' })],
        },
        {
            key: 'vehicle-device',
            labelKey: 'resource.vehicle-device',
            icon: 'microchip',
            modelNames: ['vehicle-device'],
            statusTones: { online: 'text-green-500', active: 'text-green-500', offline: 'text-gray-400', inactive: 'text-gray-400' },
            title: (device) => first(device, 'device_name', 'device_id', 'serial_number'),
            identifier: (device) => first(device, 'device_id', 'serial_number'),
            image: () => icon('microchip'),
            online: (device) => (typeof get(device, 'online') === 'boolean' ? get(device, 'online') : undefined),
            status: (device) => first(device, 'status'),
            facts: (device) => [fact('type', join([first(device, 'device_type'), first(device, 'device_model')], ' · '), { format: 'humanize' }), fact('provider', first(device, 'device_provider')), fact('serial', first(device, 'serial_number')), fact('installed', get(device, 'installation_date'), { format: 'date' })],
        },
        {
            key: 'waypoint',
            labelKey: 'resource.waypoint',
            icon: 'flag',
            modelNames: ['waypoint'],
            statusTones: { completed: 'text-green-500', pending: 'text-gray-400', in_progress: 'text-yellow-500' },
            title: (waypoint) => first(waypoint, 'displayName', 'name', 'address', 'street1', 'public_id'),
            identifier: (waypoint) => first(waypoint, 'tracking') ?? join([first(waypoint, 'city'), first(waypoint, 'country')], ', '),
            image: () => icon('flag'),
            status: (waypoint) => (get(waypoint, 'complete') ? 'completed' : first(waypoint, 'status')),
            badges: (waypoint) => badges(badge('order', 'list-ol', present(get(waypoint, 'order')) ? `#${get(waypoint, 'order')}` : null)),
            facts: (waypoint) => [fact('address', first(waypoint, 'address', 'street1')), relatedFact('customer', relation(owner, waypoint, 'customer'), first(waypoint, 'customer_type'), first(waypoint, 'customer.name')), fact('status', first(waypoint, 'status'), { format: 'humanize' }), fact('tracking', first(waypoint, 'tracking')), fact('window', join([get(waypoint, 'time_window_start'), get(waypoint, 'time_window_end')], ' – '))],
            open: parentOpener(owner, 'place', 'place'),
        },
    ];
}
