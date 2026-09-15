import { get } from '@ember/object';
import { first, present, relation, photo, icon, badge, badges, fact, relatedFact, money, join, panelOpener } from './helpers';

/**
 * Vehicles, trailers, equipment, parts, generic assets and warranties.
 */
export default function buildAssetDescriptors(owner) {
    return [
        {
            key: 'vehicle',
            labelKey: 'resource.vehicle',
            icon: 'truck',
            modelNames: ['vehicle'],
            aliases: ['attachable-vehicle', 'maintenance-subject-vehicle'],
            polymorphicTypes: ['fleet-ops:vehicle', 'Fleetbase\\FleetOps\\Models\\Vehicle'],
            permission: 'fleet-ops view vehicle',
            statusTones: { available: 'text-green-500', active: 'text-green-500', in_service: 'text-green-500', maintenance: 'text-yellow-500', unavailable: 'text-gray-400', inactive: 'text-gray-400', out_of_service: 'text-red-500' },
            title: (vehicle) => first(vehicle, 'displayName', 'display_name', 'name', 'yearMakeModel', 'public_id') ?? join([get(vehicle, 'year'), get(vehicle, 'make'), get(vehicle, 'model')]),
            identifier: (vehicle) => first(vehicle, 'plate_number', 'call_sign', 'vin', 'serial_number'),
            image: (vehicle) => photo(vehicle, 'vehicle'),
            online: (vehicle) => {
                const online = get(vehicle, 'online');

                return typeof online === 'boolean' ? online : undefined;
            },
            status: (vehicle) => first(vehicle, 'status'),
            badges: (vehicle) => {
                const driver = relation(owner, vehicle, 'driver');

                return badges(
                    badge('plate', 'id-card', first(vehicle, 'plate_number', 'call_sign', 'vehicle_number')),
                    badge('driver', 'user', first(driver, 'name', 'displayName') ?? first(vehicle, 'driver_name'), { relatedType: 'driver', relatedId: driver?.id ?? get(vehicle, 'driver_uuid') })
                );
            },
            selectDetails: (vehicle) => [first(vehicle, 'plate_number', 'vin', 'serial_number', 'call_sign'), first(vehicle, 'driver_name')],
            facts: (vehicle) => {
                const driver = relation(owner, vehicle, 'driver');
                const trailers = relation(owner, vehicle, 'trailers');
                const trailer = trailers && typeof trailers.objectAt === 'function' ? trailers.objectAt(0) : Array.isArray(trailers) ? trailers[0] : null;
                const extra = trailers?.length > 1 ? ` (+${trailers.length - 1})` : '';

                return [
                    fact('make-model', join([get(vehicle, 'year'), get(vehicle, 'make'), get(vehicle, 'model'), get(vehicle, 'trim')])),
                    fact('plate', join([first(vehicle, 'plate_number'), first(vehicle, 'vin')], ' · ')),
                    relatedFact('driver', driver, 'driver', first(vehicle, 'driver_name')),
                    trailer ? { ...relatedFact('trailer', trailer, 'trailer', first(trailer, 'displayName', 'display_name', 'name')), suffix: extra } : fact('trailer', null),
                    fact('odometer', present(get(vehicle, 'odometer')) ? join([get(vehicle, 'odometer'), get(vehicle, 'odometer_unit')]) : null),
                    fact('status', first(vehicle, 'status'), { format: 'humanize' }),
                ];
            },
            open: panelOpener(owner, 'vehicle-actions'),
            components: { identity: 'cell/vehicle-identity' },
        },
        {
            key: 'trailer',
            labelKey: 'resource.trailer',
            icon: 'trailer',
            modelNames: ['trailer'],
            aliases: ['attachable-trailer', 'maintenance-subject-trailer'],
            polymorphicTypes: ['fleet-ops:trailer', 'Fleetbase\\FleetOps\\Models\\Trailer'],
            permission: 'fleet-ops view trailer',
            statusTones: { attached: 'text-green-500', detached: 'text-gray-400' },
            title: (trailer) => first(trailer, 'displayName', 'display_name', 'name', 'public_id'),
            identifier: (trailer) => first(trailer, 'plate_number', 'vin', 'code'),
            image: (trailer) => photo(trailer, 'trailer'),
            online: (trailer) => {
                const online = get(trailer, 'isOnline') ?? get(trailer, 'online') ?? get(trailer, 'is_online');

                return typeof online === 'boolean' ? online : undefined;
            },
            status: (trailer) => first(trailer, 'status', 'attachment_state'),
            badges: (trailer) => {
                const vehicle = relation(owner, trailer, 'current_vehicle');

                return badges(
                    badge('plate', 'id-card', first(trailer, 'plate_number', 'code')),
                    badge('vehicle', 'truck', first(vehicle, 'displayName', 'display_name', 'name') ?? first(trailer, 'current_vehicle_name'), { relatedType: 'vehicle', relatedId: vehicle?.id ?? get(trailer, 'current_vehicle_id') })
                );
            },
            selectDetails: (trailer) => [first(trailer, 'type'), first(trailer, 'plate_number', 'vin', 'code')],
            facts: (trailer) => {
                const vehicle = relation(owner, trailer, 'current_vehicle');

                return [
                    fact('type', first(trailer, 'type', 'body_type'), { format: 'humanize' }),
                    fact('body-coupling', join([first(trailer, 'body_type'), first(trailer, 'coupling_type')], ' · '), { format: 'humanize' }),
                    fact('plate', join([first(trailer, 'plate_number'), first(trailer, 'vin')], ' · ')),
                    relatedFact('vehicle', vehicle, 'vehicle', first(trailer, 'current_vehicle_name')),
                    fact('axles', get(trailer, 'axle_count')),
                    fact('attachment', first(trailer, 'attachment_state'), { format: 'humanize' }),
                ];
            },
            open: panelOpener(owner, 'trailer-actions'),
            components: { identity: 'cell/trailer-identity' },
        },
        {
            key: 'equipment',
            labelKey: 'resource.equipment',
            icon: 'toolbox',
            modelNames: ['equipment'],
            aliases: ['maintenance-subject-equipment'],
            polymorphicTypes: ['fleet-ops:equipment', 'Fleetbase\\FleetOps\\Models\\Equipment'],
            permission: 'fleet-ops view equipment',
            statusTones: { active: 'text-green-500', equipped: 'text-green-500', available: 'text-green-500', maintenance: 'text-yellow-500', unequipped: 'text-gray-400', inactive: 'text-gray-400', retired: 'text-red-500' },
            title: (equipment) => first(equipment, 'name', 'code', 'public_id'),
            identifier: (equipment) => first(equipment, 'serial_number', 'code'),
            image: (equipment) => (present(get(equipment, 'photo_url')) ? { url: get(equipment, 'photo_url'), shape: 'square' } : icon('toolbox')),
            status: (equipment) => (get(equipment, 'is_equipped') ? 'equipped' : first(equipment, 'status')),
            badges: (equipment) => badges(badge('equipped-to', 'link', first(equipment, 'equipped_to_name', 'equipable.name', 'equipable.displayName'))),
            selectDetails: (equipment) => [first(equipment, 'type'), first(equipment, 'serial_number', 'code')],
            facts: (equipment) => [
                fact('type', first(equipment, 'type'), { format: 'humanize' }),
                fact('make-model', join([first(equipment, 'manufacturer'), first(equipment, 'model')])),
                fact('serial', first(equipment, 'serial_number')),
                relatedFact('equipped-to', relation(owner, equipment, 'equipable'), null, first(equipment, 'equipped_to_name')),
                relatedFact('warranty', relation(owner, equipment, 'warranty'), 'warranty', first(equipment, 'warranty_name')),
                fact('purchase-price', money(get(equipment, 'purchase_price'), get(equipment, 'currency'))),
            ],
            open: panelOpener(owner, 'equipment-actions'),
            components: { identity: 'cell/equipment-identity' },
        },
        {
            key: 'part',
            labelKey: 'resource.part',
            icon: 'gears',
            modelNames: ['part'],
            polymorphicTypes: ['fleet-ops:part', 'Fleetbase\\FleetOps\\Models\\Part'],
            permission: 'fleet-ops view part',
            statusTones: { in_stock: 'text-green-500', low_stock: 'text-yellow-500', out_of_stock: 'text-red-500' },
            title: (part) => first(part, 'name', 'sku', 'public_id'),
            identifier: (part) => first(part, 'sku', 'serial_number'),
            image: (part) => (present(get(part, 'photo_url')) ? { url: get(part, 'photo_url'), shape: 'square' } : icon('gears')),
            status: (part) => (get(part, 'is_low_stock') ? 'low_stock' : get(part, 'is_in_stock') ? 'in_stock' : get(part, 'quantity_on_hand') === undefined ? first(part, 'status') : 'out_of_stock'),
            badges: (part) => badges(badge('quantity', 'boxes-stacked', present(get(part, 'quantity_on_hand')) ? `${get(part, 'quantity_on_hand')} on hand` : null)),
            selectDetails: (part) => [first(part, 'sku'), first(part, 'manufacturer')],
            facts: (part) => [
                fact('sku', first(part, 'sku')),
                fact('make-model', join([first(part, 'manufacturer'), first(part, 'model')])),
                fact('quantity', present(get(part, 'quantity_on_hand')) ? `${get(part, 'quantity_on_hand')}${get(part, 'is_low_stock') ? ' (low stock)' : ''}` : null),
                fact('unit-cost', money(get(part, 'unit_cost'), get(part, 'currency'))),
                relatedFact('vendor', relation(owner, part, 'vendor'), 'vendor', first(part, 'vendor_name')),
                relatedFact('fitted-to', part.__fittedTo ?? null, first(part, 'asset_type'), first(part, 'asset_name')),
            ],
            hydrate: async (part) => {
                const type = first(part, 'asset_type');
                const id = first(part, 'asset_uuid');
                const registry = owner.lookup('service:resource-registry');
                const store = owner.lookup('service:store');
                const key = registry?.resolveKey(type);

                if (!key || !id || !store || part.__fittedTo) {
                    return null;
                }

                try {
                    part.__fittedTo = store.peekRecord(key, id) ?? (await store.findRecord(key, id));
                } catch {
                    part.__fittedTo = null;
                }

                return part;
            },
            open: panelOpener(owner, 'part-actions'),
            components: { identity: 'cell/part-identity' },
        },
        {
            key: 'asset',
            labelKey: 'resource.asset',
            icon: 'cube',
            modelNames: ['asset'],
            aliases: ['attachable-asset'],
            polymorphicTypes: ['fleet-ops:asset', 'Fleetbase\\FleetOps\\Models\\Asset'],
            title: (asset) => first(asset, 'display_name', 'displayName', 'name', 'public_id'),
            identifier: (asset) => first(asset, 'plate_number', 'vin', 'serial_number', 'code'),
            image: (asset) => (present(get(asset, 'photo_url')) ? { url: get(asset, 'photo_url'), shape: 'square' } : icon('cube')),
            online: (asset) => {
                const online = get(asset, 'is_online') ?? get(asset, 'online');

                return typeof online === 'boolean' ? online : undefined;
            },
            status: (asset) => first(asset, 'status'),
            badges: (asset) => badges(badge('category', 'folder', first(asset, 'category_name', 'category.name'))),
            selectDetails: (asset) => [first(asset, 'type'), first(asset, 'plate_number', 'vin', 'serial_number')],
            facts: (asset) => [
                fact('type', first(asset, 'type', 'usage_type'), { format: 'humanize' }),
                fact('make-model', join([get(asset, 'year'), get(asset, 'make'), get(asset, 'model')])),
                relatedFact('vendor', relation(owner, asset, 'vendor'), 'vendor', first(asset, 'vendor_name')),
                fact('category', first(asset, 'category_name', 'category.name')),
                fact('location', first(asset, 'current_location', 'current_place.name')),
            ],
            open: async (asset, context) => {
                const registry = owner.lookup('service:resource-registry');
                const concrete = first(asset, 'asset_class', 'type');
                const key = concrete && concrete !== 'asset' ? registry?.resolveKey(concrete) : null;

                if (key && key !== 'asset') {
                    return registry.open(asset, { ...context, resourceType: key });
                }

                return false;
            },
        },
        {
            key: 'warranty',
            labelKey: 'resource.warranty',
            icon: 'shield-halved',
            modelNames: ['warranty'],
            polymorphicTypes: ['fleet-ops:warranty', 'Fleetbase\\FleetOps\\Models\\Warranty'],
            statusTones: { active: 'text-green-500', expired: 'text-red-500', expiring: 'text-yellow-500' },
            title: (warranty) => join([first(warranty, 'provider'), first(warranty, 'policy_number')], ' · ') ?? first(warranty, 'public_id'),
            identifier: (warranty) => first(warranty, 'policy_number'),
            image: () => icon('shield-halved'),
            status: (warranty) => (get(warranty, 'is_expired') ? 'expired' : get(warranty, 'is_active') ? 'active' : first(warranty, 'status')),
            badges: (warranty) => badges(badge('days-remaining', 'hourglass-half', present(get(warranty, 'days_remaining')) ? `${get(warranty, 'days_remaining')} days` : null)),
            selectDetails: (warranty) => [first(warranty, 'provider'), first(warranty, 'policy_number')],
            facts: (warranty) => [
                fact('provider', first(warranty, 'provider')),
                fact('policy', first(warranty, 'policy_number')),
                relatedFact('vendor', relation(owner, warranty, 'vendor'), 'vendor', first(warranty, 'vendor_name')),
                fact('subject', first(warranty, 'subject_name')),
                fact('period', join([get(warranty, 'startDateShort') ?? get(warranty, 'start_date'), get(warranty, 'endDateShort') ?? get(warranty, 'end_date')], ' – ')),
                fact('coverage', first(warranty, 'coverage_summary', 'coverage')),
            ],
        },
    ];
}
