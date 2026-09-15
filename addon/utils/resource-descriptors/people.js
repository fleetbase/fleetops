import { get } from '@ember/object';
import { first, present, relation, photo, icon, badge, badges, fact, relatedFact, join, panelOpener } from './helpers';

/**
 * Drivers, vendors, contacts, customers and fleets.
 */
export default function buildPeopleDescriptors(owner) {
    return [
        {
            key: 'driver',
            labelKey: 'resource.driver',
            icon: 'id-card',
            modelNames: ['driver'],
            aliases: ['attachable-driver', 'facilitator-driver', 'fleet-driver'],
            polymorphicTypes: ['fleet-ops:driver', 'Fleetbase\\FleetOps\\Models\\Driver'],
            permission: 'fleet-ops view driver',
            statusTones: { available: 'text-green-500', active: 'text-green-500', on_duty: 'text-green-500', busy: 'text-yellow-500', assigned: 'text-yellow-500', unavailable: 'text-gray-400', offline: 'text-gray-400', suspended: 'text-red-500' },
            title: (driver) => first(driver, 'name', 'displayName', 'display_name', 'public_id'),
            identifier: (driver) => first(driver, 'phone', 'email'),
            image: (driver) => photo(driver, 'driver'),
            online: (driver) => {
                const online = get(driver, 'online');

                return typeof online === 'boolean' ? online : undefined;
            },
            status: (driver) => first(driver, 'status'),
            badges: (driver, context = {}) => {
                const vehicle = relation(owner, driver, 'vehicle');
                const label = context.assignedVehicleLabel ?? first(vehicle, 'displayName', 'display_name', 'name') ?? first(driver, 'vehicle_assigned.display_name', 'vehicle_name');

                return badges(badge('vehicle', 'car', label, { relatedType: 'vehicle', relatedId: vehicle?.id ?? get(driver, 'vehicle_uuid') }));
            },
            selectDetails: (driver) => [first(driver, 'phone'), first(driver, 'email')],
            facts: (driver) => [
                fact('phone', first(driver, 'phone')),
                fact('email', first(driver, 'email')),
                relatedFact('vehicle', relation(owner, driver, 'vehicle'), 'vehicle', first(driver, 'vehicle_name')),
                fact('licence', join([first(driver, 'drivers_license_number'), present(get(driver, 'license_expiry')) ? `expires ${new Date(get(driver, 'license_expiry')).toLocaleDateString()}` : null], ' · ')),
                relatedFact('vendor', relation(owner, driver, 'vendor'), 'vendor', first(driver, 'vendor_name')),
                fact('status', first(driver, 'status'), { format: 'humanize' }),
            ],
            open: panelOpener(owner, 'driver-actions'),
            components: { identity: 'cell/driver-identity' },
        },
        {
            key: 'vendor',
            labelKey: 'resource.vendor',
            icon: 'building',
            modelNames: ['vendor'],
            aliases: ['facilitator-vendor', 'customer-vendor'],
            polymorphicTypes: ['fleet-ops:vendor', 'Fleetbase\\FleetOps\\Models\\Vendor'],
            permission: 'fleet-ops view vendor',
            title: (vendor) => first(vendor, 'name', 'public_id'),
            identifier: (vendor) => first(vendor, 'prettyType', 'internal_id', 'business_id'),
            image: (vendor) => photo(vendor, 'vendor', 'logo_url'),
            status: (vendor) => first(vendor, 'status'),
            badges: (vendor) => badges(badge('type', 'tag', first(vendor, 'prettyType', 'type'))),
            selectDetails: (vendor) => [first(vendor, 'email'), first(vendor, 'phone')],
            facts: (vendor) => [
                fact('type', first(vendor, 'prettyType', 'type'), { format: 'humanize' }),
                fact('email', first(vendor, 'email')),
                fact('phone', first(vendor, 'phone')),
                fact('website', first(vendor, 'website_url')),
                fact('address', first(vendor, 'address', 'place.address', 'address_street')),
                fact('country', first(vendor, 'country')),
            ],
            open: panelOpener(owner, 'vendor-actions'),
        },
        {
            key: 'integrated-vendor',
            labelKey: 'resource.integrated-vendor',
            icon: 'plug',
            modelNames: ['integrated-vendor'],
            aliases: ['facilitator-integrated-vendor'],
            polymorphicTypes: ['fleet-ops:integrated-vendor', 'Fleetbase\\FleetOps\\Models\\IntegratedVendor'],
            title: (vendor) => first(vendor, 'name', 'provider', 'public_id'),
            identifier: (vendor) => first(vendor, 'provider'),
            image: (vendor) => photo(vendor, 'vendor', 'logo_url'),
            status: (vendor) => first(vendor, 'status'),
            badges: (vendor) => badges(badge('provider', 'plug', first(vendor, 'provider')), get(vendor, 'sandbox') ? badge('sandbox', 'flask', 'sandbox') : null),
            selectDetails: (vendor) => [first(vendor, 'provider'), get(vendor, 'sandbox') ? 'sandbox' : null],
            facts: (vendor) => [
                fact('provider', first(vendor, 'provider')),
                fact('status', first(vendor, 'status'), { format: 'humanize' }),
                fact('sandbox', typeof get(vendor, 'sandbox') === 'boolean' ? get(vendor, 'sandbox') : null),
                fact('host', first(vendor, 'host')),
                fact('countries', Array.isArray(get(vendor, 'supported_countries')) ? get(vendor, 'supported_countries').join(', ') : null),
                fact('service-types', Array.isArray(get(vendor, 'service_types')) ? get(vendor, 'service_types').join(', ') : null),
            ],
            open: panelOpener(owner, 'integrated-vendor-actions'),
        },
        {
            key: 'contact',
            labelKey: 'resource.contact',
            icon: 'address-book',
            modelNames: ['contact'],
            aliases: ['facilitator-contact', 'customer-contact'],
            polymorphicTypes: ['fleet-ops:contact', 'Fleetbase\\FleetOps\\Models\\Contact'],
            permission: 'fleet-ops view contact',
            title: (contact) => first(contact, 'name', 'public_id'),
            identifier: (contact) => first(contact, 'title', 'type'),
            image: (contact) => photo(contact, 'contact'),
            badges: (contact) => badges(badge('type', 'tag', first(contact, 'type'))),
            selectDetails: (contact) => [first(contact, 'email'), first(contact, 'phone')],
            facts: (contact) => [
                fact('title', first(contact, 'title')),
                fact('email', first(contact, 'email')),
                fact('phone', first(contact, 'phone')),
                relatedFact('place', relation(owner, contact, 'place'), 'place', first(contact, 'address', 'place.address')),
                relatedFact('user', relation(owner, contact, 'user'), 'user', first(contact, 'user.name')),
            ],
            open: panelOpener(owner, 'contact-actions'),
        },
        {
            key: 'customer',
            labelKey: 'resource.customer',
            icon: 'user-tag',
            modelNames: ['customer'],
            aliases: ['facilitator-customer'],
            polymorphicTypes: ['fleet-ops:customer', 'Fleetbase\\FleetOps\\Models\\Customer'],
            permission: 'fleet-ops view contact',
            title: (customer) => first(customer, 'name', 'public_id'),
            identifier: (customer) => first(customer, 'customer_type', 'type'),
            image: (customer) => photo(customer, 'customer'),
            badges: (customer) => badges(badge('type', 'tag', first(customer, 'customer_type', 'type'))),
            selectDetails: (customer) => [first(customer, 'email'), first(customer, 'phone')],
            facts: (customer) => [
                fact('type', first(customer, 'customer_type', 'type'), { format: 'humanize' }),
                fact('email', first(customer, 'email')),
                fact('phone', first(customer, 'phone')),
                fact('address', first(customer, 'address', 'address_street')),
                relatedFact('place', relation(owner, customer, 'place'), 'place', first(customer, 'place.address')),
            ],
            open: panelOpener(owner, 'customer-actions'),
        },
        {
            key: 'fleet',
            labelKey: 'resource.fleet',
            icon: 'layer-group',
            modelNames: ['fleet'],
            polymorphicTypes: ['fleet-ops:fleet', 'Fleetbase\\FleetOps\\Models\\Fleet'],
            permission: 'fleet-ops view fleet',
            title: (fleet) => first(fleet, 'name', 'public_id'),
            identifier: (fleet) => first(fleet, 'task', 'public_id'),
            image: (fleet) => photo(fleet, 'fleet'),
            status: (fleet) => first(fleet, 'status'),
            badges: (fleet) => {
                const online = get(fleet, 'drivers_online_count');
                const total = get(fleet, 'drivers_count');

                return badges(badge('drivers', 'id-card', present(total) ? `${online ?? 0}/${total} online` : null));
            },
            selectDetails: (fleet) => [first(fleet, 'task'), present(get(fleet, 'drivers_count')) ? `${get(fleet, 'drivers_count')} drivers` : null],
            facts: (fleet) => [
                fact('task', first(fleet, 'task')),
                relatedFact('service-area', relation(owner, fleet, 'service_area'), 'service-area', first(fleet, 'service_area.name')),
                relatedFact('zone', relation(owner, fleet, 'zone'), 'zone', first(fleet, 'zone.name')),
                relatedFact('vendor', relation(owner, fleet, 'vendor'), 'vendor', first(fleet, 'vendor.name')),
                fact('drivers', present(get(fleet, 'drivers_count')) ? `${get(fleet, 'drivers_online_count') ?? 0} online of ${get(fleet, 'drivers_count')}` : null),
                fact('vehicles', present(get(fleet, 'vehicles_count')) ? `${get(fleet, 'vehicles_online_count') ?? 0} online of ${get(fleet, 'vehicles_count')}` : null),
            ],
            open: panelOpener(owner, 'fleet-actions'),
        },
    ];
}
