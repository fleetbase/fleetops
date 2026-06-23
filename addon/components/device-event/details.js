import Component from '@glimmer/component';
import { action, get } from '@ember/object';
import { inject as service } from '@ember/service';

const warningSeverities = ['warning', 'error', 'critical', 'high'];

function firstPresent(...values) {
    return values.find((value) => value !== undefined && value !== null && value !== '');
}

function prettyJson(value) {
    if (!value) {
        return null;
    }

    if (typeof value === 'string') {
        try {
            return JSON.stringify(JSON.parse(value), null, 2);
        } catch {
            return value;
        }
    }

    return JSON.stringify(value, null, 2);
}

export default class DeviceEventDetailsComponent extends Component {
    @service hostRouter;

    get resource() {
        return this.args.resource ?? {};
    }

    get eventType() {
        return firstPresent(get(this.resource, 'event_type'), get(this.resource, 'eventType'), get(this.resource, 'type'));
    }

    get severity() {
        return firstPresent(get(this.resource, 'severity'), 'info');
    }

    get isProcessed() {
        return Boolean(firstPresent(get(this.resource, 'processed_at'), get(this.resource, 'processedAt')));
    }

    get processedStatus() {
        return this.isProcessed ? 'success' : 'warning';
    }

    get processedLabel() {
        return this.isProcessed ? 'Processed' : 'Unprocessed';
    }

    get severityAccentClass() {
        return warningSeverities.includes(String(this.severity ?? '').toLowerCase()) ? 'fleetops-connectivity-kpi-accent-amber' : 'fleetops-connectivity-kpi-accent-green';
    }

    get message() {
        return get(this.resource, 'message');
    }

    get occurredAt() {
        return firstPresent(get(this.resource, 'occurred_at'), get(this.resource, 'occurredAt'), get(this.resource, 'created_at'), get(this.resource, 'createdAt'));
    }

    get processedAt() {
        return firstPresent(get(this.resource, 'processed_at'), get(this.resource, 'processedAt'));
    }

    get processingDelay() {
        return firstPresent(get(this.resource, 'processing_delay_minutes'), get(this.resource, 'processingDelayMinutes'));
    }

    get ageMinutes() {
        return firstPresent(get(this.resource, 'age_minutes'), get(this.resource, 'ageMinutes'));
    }

    get deviceName() {
        return firstPresent(
            get(this.resource, 'device.displayName'),
            get(this.resource, 'device.display_name'),
            get(this.resource, 'device.name'),
            get(this.resource, 'device_name'),
            get(this.resource, 'device_id')
        );
    }

    get deviceIdentifier() {
        return firstPresent(
            get(this.resource, 'device_id'),
            get(this.resource, 'device.device_id'),
            get(this.resource, 'device_imei'),
            get(this.resource, 'device_serial_number'),
            get(this.resource, 'ident')
        );
    }

    get deviceRouteModel() {
        return firstPresent(get(this.resource, 'device.id'), get(this.resource, 'device.public_id'), get(this.resource, 'device_uuid'));
    }

    get providerLabel() {
        return firstPresent(get(this.resource, 'provider_descriptor.label'), get(this.resource, 'telematic.name'), get(this.resource, 'telematic_name'), get(this.resource, 'provider'));
    }

    get telematicRouteModel() {
        return firstPresent(get(this.resource, 'telematic.id'), get(this.resource, 'telematic.public_id'), get(this.resource, 'telematic_uuid'));
    }

    get deviceStatus() {
        return firstPresent(get(this.resource, 'device.status'), get(this.resource, 'device_status'), get(this.resource, 'device_connection_status'));
    }

    get metrics() {
        return [
            { label: 'Severity', value: this.severity, icon: 'triangle-exclamation', accentClass: this.severityAccentClass },
            {
                label: 'Processing',
                value: this.processedLabel,
                icon: this.isProcessed ? 'check' : 'clock',
                accentClass: this.isProcessed ? 'fleetops-connectivity-kpi-accent-green' : 'fleetops-connectivity-kpi-accent-rose',
            },
            { label: 'Device', value: this.deviceName, meta: this.deviceIdentifier, icon: 'microchip', accentClass: 'fleetops-connectivity-kpi-accent-blue' },
            { label: 'Provider', value: this.providerLabel, icon: 'satellite-dish', accentClass: 'fleetops-connectivity-kpi-accent-green' },
        ];
    }

    get payloadJson() {
        return prettyJson(get(this.resource, 'payload'));
    }

    get dataJson() {
        return prettyJson(get(this.resource, 'data'));
    }

    get metaJson() {
        return prettyJson(get(this.resource, 'meta'));
    }

    @action openDevice() {
        if (this.deviceRouteModel) {
            return this.hostRouter.transitionTo('console.fleet-ops.connectivity.devices.index.details', this.deviceRouteModel);
        }
    }

    @action openTelematic() {
        if (this.telematicRouteModel) {
            return this.hostRouter.transitionTo('console.fleet-ops.connectivity.telematics.details', this.telematicRouteModel);
        }
    }
}
