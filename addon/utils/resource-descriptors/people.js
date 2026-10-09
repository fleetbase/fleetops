import { get } from '@ember/object';
import { first, photo, fact, panelOpener } from './helpers';
import { sharedDescriptors } from './shared';

/**
 * Drivers, vendors, contacts, customers and fleets.
 */
export default function buildPeopleDescriptors(owner) {
    return [
        ...sharedDescriptors(owner, ['driver', 'vendor', 'contact', 'customer', 'fleet']),
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
    ];
}
