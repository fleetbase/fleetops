import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';

const warningSeverities = ['warning', 'error', 'critical', 'high'];

export default class DeviceDetailsComponent extends Component {
    @service store;
    @service intl;

    @tracked sensors = [];
    @tracked events = [];
    @tracked sensorTotal;
    @tracked eventTotal;

    constructor() {
        super(...arguments);
        this.loadOperationalSummary.perform();
    }

    get resource() {
        return this.args.resource;
    }

    get displayName() {
        return this.resource?.displayName ?? this.resource?.name ?? this.resource?.device_id ?? this.resource?.serial_number ?? this.resource?.public_id ?? this.intl.t('resource.device');
    }

    get providerDescriptor() {
        return this.resource?.telematic?.provider_descriptor;
    }

    get providerLabel() {
        return this.providerDescriptor?.label ?? this.resource?.telematic?.name ?? this.resource?.provider ?? this.resource?.telematic_name;
    }

    get providerIcon() {
        return this.providerDescriptor?.icon;
    }

    get connectionStatus() {
        return this.resource?.connection_status ?? (this.resource?.is_online ? 'online' : 'offline');
    }

    get connectionTone() {
        switch (this.connectionStatus) {
            case 'online':
                return 'online';
            case 'recently_offline':
                return 'warning';
            case 'never_connected':
            case 'long_offline':
                return 'danger';
            default:
                return 'offline';
        }
    }

    get connectionLabel() {
        return this.connectionStatus ? this.connectionStatus.replaceAll('_', ' ') : 'Unknown';
    }

    get attachedVehicleName() {
        return this.resource?.attached_to_name ?? this.resource?.attachable?.displayName ?? this.resource?.attachable?.display_name ?? this.resource?.attachable?.name;
    }

    get attachmentLabel() {
        return this.attachedVehicleName ?? 'Unattached';
    }

    get attachmentTone() {
        return this.attachedVehicleName ? 'success' : 'warning';
    }

    get lastSeenLabel() {
        return this.resource?.last_online_at ?? this.resource?.lastOnlineAt;
    }

    get signalLabel() {
        return this.resource?.signal_strength ?? this.resource?.data?.signal_strength ?? this.resource?.meta?.signal_strength;
    }

    get firmwareLabel() {
        return this.resource?.firmware_version ?? this.resource?.data?.firmware_version ?? this.resource?.meta?.firmware_version;
    }

    get lastPositionLabel() {
        const position = this.resource?.last_position;

        if (!position) {
            return null;
        }

        if (typeof position === 'string') {
            return position;
        }

        const latitude = position.latitude ?? position.lat ?? position.coordinates?.[1];
        const longitude = position.longitude ?? position.lng ?? position.lon ?? position.coordinates?.[0];

        if (latitude && longitude) {
            return `${latitude}, ${longitude}`;
        }

        return null;
    }

    get warningEventsCount() {
        return this.events.filter((event) => warningSeverities.includes(String(event.severity ?? '').toLowerCase())).length;
    }

    get unprocessedEventsCount() {
        return this.events.filter((event) => !event.processed_at && !event.is_processed).length;
    }

    get activeSensorsCount() {
        return this.sensors.filter((sensor) => sensor.is_active || sensor.status === 'active' || sensor.status === 'online').length;
    }

    get reportingSensorsCount() {
        return this.sensors.filter((sensor) => sensor.last_value || sensor.last_reading_at).length;
    }

    get metrics() {
        return [
            {
                label: 'Connection',
                value: this.connectionLabel,
                icon: this.connectionStatus === 'online' ? 'signal' : 'power-off',
                accentClass: this.connectionStatus === 'online' ? 'fleetops-connectivity-kpi-accent-green' : 'fleetops-connectivity-kpi-accent-rose',
            },
            {
                label: 'Sensors',
                value: this.sensorTotal ?? this.sensors.length,
                meta: `${this.activeSensorsCount} active`,
                icon: 'gauge-high',
                accentClass: 'fleetops-connectivity-kpi-accent-blue',
            },
            {
                label: 'Events',
                value: this.eventTotal ?? this.events.length,
                meta: `${this.warningEventsCount + this.unprocessedEventsCount} need review`,
                icon: 'bolt',
                accentClass: this.warningEventsCount || this.unprocessedEventsCount ? 'fleetops-connectivity-kpi-accent-amber' : 'fleetops-connectivity-kpi-accent-green',
            },
            {
                label: 'Vehicle',
                value: this.attachmentLabel,
                icon: this.attachedVehicleName ? 'truck' : 'link-slash',
                accentClass: this.attachedVehicleName ? 'fleetops-connectivity-kpi-accent-green' : 'fleetops-connectivity-kpi-accent-amber',
            },
        ];
    }

    @task *loadOperationalSummary() {
        if (!this.resource?.id) {
            return;
        }

        try {
            const [sensors, events] = yield Promise.all([
                this.store.query('sensor', { device_uuid: this.resource.id, limit: 10 }),
                this.store.query('device-event', { device_uuid: this.resource.id, limit: 10, sort: '-created_at' }),
            ]);

            this.sensors = Array.from(sensors ?? []);
            this.events = Array.from(events ?? []);
            this.sensorTotal = sensors?.meta?.total;
            this.eventTotal = events?.meta?.total;
        } catch {
            this.sensors = [];
            this.events = [];
        }
    }
}
