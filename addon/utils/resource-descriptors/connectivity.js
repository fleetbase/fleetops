import { get } from '@ember/object';
import { first, present, relation, icon, badge, badges, fact, relatedFact, join, panelOpener } from './helpers';

const DEFAULT_PROVIDER_ICON = '/engines-dist/images/telematics/providers/default.webp';

function providerImage(record) {
    const descriptor = get(record, 'provider_descriptor') ?? {};
    const url = descriptor.icon ?? get(record, 'provider_icon');

    return present(url) ? { url, fallback: DEFAULT_PROVIDER_ICON, shape: 'square' } : icon('satellite-dish');
}

/**
 * Devices, sensors, telematics providers and device events.
 */
export default function buildConnectivityDescriptors(owner) {
    return [
        {
            key: 'device',
            labelKey: 'resource.device',
            icon: 'microchip',
            modelNames: ['device'],
            polymorphicTypes: ['fleet-ops:device', 'Fleetbase\\FleetOps\\Models\\Device'],
            permission: 'fleet-ops view device',
            statusTones: { online: 'text-green-500', active: 'text-green-500', recently_offline: 'text-yellow-500', offline: 'text-gray-400', long_offline: 'text-gray-400', never_connected: 'text-gray-400', inactive: 'text-gray-400', error: 'text-red-500' },
            title: (device) => first(device, 'displayName', 'display_name', 'name', 'device_id', 'imei', 'serial_number', 'public_id'),
            identifier: (device) => first(device, 'device_id', 'imei', 'serial_number'),
            image: (device) => (present(get(device, 'photo_url')) ? { url: get(device, 'photo_url'), shape: 'square' } : icon('microchip')),
            online: (device) => {
                const online = get(device, 'is_online') ?? get(device, 'online');

                return typeof online === 'boolean' ? online : undefined;
            },
            status: (device) => first(device, 'connection_status', 'status'),
            badges: (device) => {
                const attachable = relation(owner, device, 'attachable');

                return badges(badge('attached-to', 'link', first(attachable, 'displayName', 'display_name', 'name') ?? first(device, 'attached_to_name'), { relatedId: attachable?.id ?? get(device, 'attachable_uuid') }));
            },
            selectDetails: (device) => [first(device, 'type', 'model'), first(device, 'imei', 'serial_number', 'device_id')],
            facts: (device) => [
                fact('type', join([first(device, 'type'), first(device, 'model')], ' · '), { format: 'humanize' }),
                fact('imei', join([first(device, 'imei'), first(device, 'serial_number')], ' · ')),
                relatedFact('telematic', relation(owner, device, 'telematic'), 'telematic', first(device, 'telematic_name')),
                relatedFact('attached-to', relation(owner, device, 'attachable'), null, first(device, 'attached_to_name')),
                fact('last-online', get(device, 'last_online_at'), { format: 'date' }),
                fact('firmware', first(device, 'firmware_version')),
            ],
            open: panelOpener(owner, 'device-actions'),
            components: { identity: 'cell/device-identity' },
        },
        {
            key: 'sensor',
            labelKey: 'resource.sensor',
            icon: 'gauge',
            modelNames: ['sensor'],
            polymorphicTypes: ['fleet-ops:sensor', 'Fleetbase\\FleetOps\\Models\\Sensor'],
            permission: 'fleet-ops view sensor',
            statusTones: { normal: 'text-green-500', ok: 'text-green-500', warning: 'text-yellow-500', breached: 'text-red-500', critical: 'text-red-500' },
            title: (sensor) => first(sensor, 'displayName', 'display_name', 'name', 'internal_id', 'public_id'),
            identifier: (sensor) => first(sensor, 'serial_number', 'internal_id', 'imei'),
            image: (sensor) => (present(get(sensor, 'photo_url')) ? { url: get(sensor, 'photo_url'), shape: 'square' } : icon('gauge')),
            status: (sensor) => first(sensor, 'threshold_status', 'status'),
            badges: (sensor) => {
                const device = relation(owner, sensor, 'device');

                return badges(badge('device', 'microchip', first(device, 'displayName', 'display_name', 'name') ?? first(sensor, 'device_name'), { relatedType: 'device', relatedId: device?.id ?? get(sensor, 'device_uuid') }));
            },
            selectDetails: (sensor) => [first(sensor, 'type'), first(sensor, 'serial_number', 'internal_id')],
            facts: (sensor) => [
                fact('type', first(sensor, 'type'), { format: 'humanize' }),
                fact('last-value', join([get(sensor, 'last_value'), first(sensor, 'unit')])),
                fact('threshold', present(get(sensor, 'min_threshold')) || present(get(sensor, 'max_threshold')) ? `${get(sensor, 'min_threshold') ?? '–'} to ${get(sensor, 'max_threshold') ?? '–'}` : null),
                relatedFact('device', relation(owner, sensor, 'device'), 'device', first(sensor, 'device_name')),
                relatedFact('telematic', relation(owner, sensor, 'telematic'), 'telematic', first(sensor, 'telematic_name')),
                fact('last-reading', get(sensor, 'last_reading_at'), { format: 'date' }),
            ],
            open: panelOpener(owner, 'sensor-actions'),
        },
        {
            key: 'telematic',
            labelKey: 'resource.telematic',
            icon: 'satellite-dish',
            modelNames: ['telematic'],
            polymorphicTypes: ['fleet-ops:telematic', 'Fleetbase\\FleetOps\\Models\\Telematic'],
            permission: 'fleet-ops view telematic',
            title: (telematic) => first(telematic, 'name', 'provider_descriptor.label', 'provider', 'public_id'),
            identifier: (telematic) => first(telematic, 'provider_descriptor.label', 'provider', 'serial_number'),
            image: providerImage,
            status: (telematic) => first(telematic, 'status'),
            badges: (telematic) => badges(badge('provider', 'satellite-dish', first(telematic, 'provider_descriptor.label', 'provider'))),
            selectDetails: (telematic) => [first(telematic, 'provider_descriptor.label', 'provider'), first(telematic, 'serial_number', 'imei')],
            facts: (telematic) => [
                fact('provider', first(telematic, 'provider_descriptor.label', 'provider')),
                fact('model', first(telematic, 'model')),
                fact('serial', join([first(telematic, 'serial_number'), first(telematic, 'imei')], ' · ')),
                fact('status', first(telematic, 'status'), { format: 'humanize' }),
                fact('last-seen', get(telematic, 'last_seen_at'), { format: 'date' }),
                relatedFact('warranty', relation(owner, telematic, 'warranty'), 'warranty', first(telematic, 'warranty_name')),
            ],
            open: panelOpener(owner, 'telematic-actions'),
            components: { identity: 'cell/telematic-identity' },
        },
        {
            key: 'device-event',
            labelKey: 'resource.device-event',
            icon: 'bolt',
            modelNames: ['device-event'],
            polymorphicTypes: ['fleet-ops:device-event', 'Fleetbase\\FleetOps\\Models\\DeviceEvent'],
            permission: 'fleet-ops view device-event',
            statusTones: { info: 'text-gray-400', low: 'text-gray-400', notice: 'text-yellow-500', warning: 'text-yellow-500', medium: 'text-yellow-500', high: 'text-red-500', critical: 'text-red-500', error: 'text-red-500' },
            title: (event) => {
                const type = first(event, 'event_type', 'code');

                return type ? String(type).replace(/[_.-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : first(event, 'public_id');
            },
            identifier: (event) => first(event, 'ident', 'public_id'),
            image: (event) => (present(get(event, 'device_photo_url')) ? { url: get(event, 'device_photo_url'), shape: 'square' } : icon('bolt')),
            status: (event) => first(event, 'severity'),
            badges: (event) => badges(badge('device', 'microchip', first(event, 'device_name', 'device.displayName', 'device_id'), { relatedType: 'device', relatedId: get(event, 'device_uuid') })),
            selectDetails: (event) => [first(event, 'device_name'), first(event, 'severity')],
            facts: (event) => [
                fact('event-type', first(event, 'event_type', 'code'), { format: 'humanize' }),
                fact('severity', first(event, 'severity'), { format: 'humanize' }),
                relatedFact('device', relation(owner, event, 'device'), 'device', first(event, 'device_name')),
                fact('telematic', first(event, 'telematic_name', 'provider')),
                fact('occurred', get(event, 'occurred_at') ?? get(event, 'created_at'), { format: 'date' }),
                fact('reason', first(event, 'reason', 'comment')),
            ],
            open: panelOpener(owner, 'device-event-actions'),
        },
    ];
}
