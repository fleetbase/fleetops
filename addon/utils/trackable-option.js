import getModelName from '@fleetbase/ember-core/utils/get-model-name';
import { isArray } from '@ember/array';
import { get } from '@ember/object';

export function buildTrackableOption(resource, modelName = getTrackableModelName(resource)) {
    const deviceLabel = trackableDeviceLabel(resource, modelName);
    const secondaryLabel = trackableSecondaryLabel(resource, modelName);
    const primaryLabel = getValue(resource, 'name') ?? getValue(resource, 'displayName') ?? getValue(resource, 'display_name') ?? getValue(resource, 'public_id');
    const trackableSearchText = [
        primaryLabel,
        secondaryLabel,
        getValue(resource, 'public_id'),
        getValue(resource, 'internal_id'),
        getValue(resource, 'serial_number'),
        getValue(resource, 'plate_number'),
        getValue(resource, 'email'),
        getValue(resource, 'vin'),
        getValue(resource, 'call_sign'),
        deviceLabel,
    ]
        .filter(Boolean)
        .join(' ');

    return {
        resource,
        modelName,
        primaryLabel,
        secondaryLabel,
        deviceLabel,
        trackableSearchText,
    };
}

export function trackableSecondaryLabel(resource, modelName = getTrackableModelName(resource)) {
    if (modelName === 'driver') {
        return getValue(resource, 'email') ?? getValue(resource, 'phone') ?? getValue(resource, 'vehicle_name') ?? getValue(resource, 'internal_id') ?? getValue(resource, 'public_id');
    }

    return getValue(resource, 'plate_number') ?? getValue(resource, 'serial_number') ?? getValue(resource, 'internal_id') ?? getValue(resource, 'vin') ?? getValue(resource, 'public_id');
}

export function trackableDeviceLabel(resource, modelName = getTrackableModelName(resource)) {
    const devices = trackableDevices(resource, modelName);
    const labels = devices.map(deviceLabel).filter(Boolean);

    if (labels.length === 0) {
        return null;
    }

    return labels.length === 1 ? `Device: ${labels[0]}` : `Devices: ${labels.join(', ')}`;
}

export function trackableDevices(resource, modelName = getTrackableModelName(resource)) {
    const devices = modelName === 'driver' ? getValue(resource, 'vehicle.devices') : getValue(resource, 'devices');

    if (!devices) {
        return [];
    }

    if (isArray(devices)) {
        return devices;
    }

    if (typeof devices.toArray === 'function') {
        return devices.toArray();
    }

    return [];
}

export function deviceLabel(device) {
    return (
        getValue(device, 'displayName') ??
        getValue(device, 'display_name') ??
        getValue(device, 'name') ??
        getValue(device, 'device_id') ??
        getValue(device, 'serial_number') ??
        getValue(device, 'imei') ??
        getValue(device, 'public_id')
    );
}

function getTrackableModelName(resource) {
    return getModelName(resource) ?? getValue(resource, 'modelName') ?? resource?.constructor?.modelName;
}

function getValue(object, path) {
    if (!object) {
        return undefined;
    }

    return get(object, path);
}
