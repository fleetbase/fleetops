import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class ConnectivityTelematicsDetailsAttachmentsController extends Controller {
    @service deviceActions;
    @service hostRouter;
    @service intl;
    @service modalsManager;
    @service notifications;

    @tracked queryParams = ['query', 'status', 'attachment_state', 'vehicle', 'sort'];
    @tracked telematic;
    @tracked query;
    @tracked status;
    @tracked attachment_state;
    @tracked vehicle;
    @tracked sort = '-updated_at';

    get syncedDevices() {
        return Array.from(this.model ?? []);
    }

    get devices() {
        const query = String(this.query ?? '')
            .trim()
            .toLowerCase();

        return this.syncedDevices.filter((device) => {
            const matchesQuery =
                !query ||
                [device.displayName, device.name, device.device_id, device.serial_number, device.imei, device.attached_to_name, device.status, device.connection_status]
                    .filter(Boolean)
                    .some((value) => String(value).toLowerCase().includes(query));

            const matchesStatus = !this.status || device.status === this.status || device.connection_status === this.status;
            const matchesAttachmentState =
                !this.attachment_state || (this.attachment_state === 'attached' && device.attachable_uuid) || (this.attachment_state === 'unattached' && !device.attachable_uuid);
            const matchesVehicle = !this.vehicle || device.attachable_uuid === this.vehicle;

            return matchesQuery && matchesStatus && matchesAttachmentState && matchesVehicle;
        });
    }

    get unattachedDevices() {
        return this.devices.filter((device) => !device.attachable_uuid);
    }

    get attachedDevices() {
        return this.devices.filter((device) => device.attachable_uuid);
    }

    get onlineDevicesCount() {
        return this.devices.filter((device) => device.is_online || device.connection_status === 'online' || device.status === 'online' || device.status === 'active').length;
    }

    get vehicleGroups() {
        const groups = new Map();

        for (const device of this.attachedDevices) {
            this.addDeviceToVehicleGroup(groups, device);
        }

        return this.sortVehicleGroups(groups);
    }

    addDeviceToVehicleGroup(groups, device) {
        const key = device.attachable_uuid;

        if (!groups.has(key)) {
            groups.set(key, {
                id: key,
                name: device.attached_to_name ?? 'Unknown vehicle',
                devices: [],
            });
        }

        groups.get(key).devices.push(device);
    }

    sortVehicleGroups(groups) {
        return Array.from(groups.values()).sort((a, b) => String(a.name).localeCompare(String(b.name)));
    }

    get hasActiveFilters() {
        return Boolean(this.query || this.status || this.attachment_state || this.vehicle);
    }

    get hasSyncedDevices() {
        return this.syncedDevices.length > 0;
    }

    get hasVisibleDevices() {
        return this.devices.length > 0;
    }

    get hasOnlyUnattachedDevices() {
        return this.unattachedDevices.length > 0 && this.vehicleGroups.length === 0 && !this.hasActiveFilters;
    }

    get allMapped() {
        return this.hasSyncedDevices && this.unattachedDevices.length === 0 && this.vehicleGroups.length > 0 && !this.hasActiveFilters;
    }

    get emptyStateVariant() {
        if (!this.hasSyncedDevices) {
            return 'not_synced';
        }

        if (this.hasActiveFilters && !this.hasVisibleDevices) {
            return 'filtered_empty';
        }

        if (this.allMapped) {
            return 'empty';
        }

        if (this.hasOnlyUnattachedDevices) {
            return 'unattached_only';
        }

        return null;
    }

    get emptyStateContent() {
        switch (this.emptyStateVariant) {
            case 'not_synced':
                return {
                    tone: 'warning',
                    icon: 'satellite-dish',
                    title: 'Sync devices before mapping vehicles',
                    message: 'Vehicle attachment starts after provider devices are synced into this connection.',
                    primaryText: 'Go to Devices',
                    primaryIcon: 'microchip',
                    primaryAction: this.goToDevices,
                };
            case 'filtered_empty':
                return {
                    tone: 'info',
                    icon: 'filter',
                    title: 'No mappings match these filters',
                    message: 'Clear the search and filters to return to all synced devices and vehicle mappings.',
                    primaryText: 'Clear filters',
                    primaryIcon: 'filter',
                    primaryAction: this.clearFilters,
                };
            case 'empty':
                return {
                    tone: 'success',
                    icon: 'check',
                    title: 'All synced devices are mapped',
                    message: 'Every synced provider device in this connection is currently attached to a vehicle.',
                };
            case 'unattached_only':
                return {
                    tone: 'warning',
                    icon: 'link-slash',
                    title: 'Attach synced devices to vehicles',
                    message: 'Use the unattached devices section below to map each device deliberately. Bulk auto-attachment is intentionally not available here.',
                };
            default:
                return null;
        }
    }

    get metrics() {
        return [
            { label: 'Vehicles mapped', value: this.vehicleGroups.length, icon: 'truck', accentClass: 'fleetops-connectivity-kpi-accent-blue' },
            { label: 'Attached devices', value: this.attachedDevices.length, icon: 'link', accentClass: 'fleetops-connectivity-kpi-accent-green' },
            { label: 'Unattached devices', value: this.unattachedDevices.length, icon: 'link-slash', accentClass: 'fleetops-connectivity-kpi-accent-amber' },
            { label: 'Online devices', value: this.onlineDevicesCount, icon: 'signal', accentClass: 'fleetops-connectivity-kpi-accent-green' },
        ];
    }

    @action updateQuery(event) {
        this.query = event.target.value;
    }

    @action updateAttachmentState(event) {
        this.attachment_state = event.target.value || null;
    }

    @action updateStatus(event) {
        this.status = event.target.value || null;
    }

    @action updateVehicle(valueOrEvent) {
        const value = valueOrEvent?.target ? valueOrEvent.target.value : valueOrEvent;

        this.vehicle = value || null;
    }

    @action clearFilters() {
        this.query = null;
        this.status = null;
        this.attachment_state = null;
        this.vehicle = null;
    }

    @action goToDevices() {
        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.telematics.details.devices', this.telematic);
    }

    @action openAttachDeviceModal(device) {
        this.modalsManager.show('modals/attach-telematic-device', {
            title: this.intl.t('device.prompts.attach-device-to-vehicle-title', { deviceName: device.displayName ?? device.name ?? device.device_id ?? this.intl.t('resource.device') }),
            acceptButtonText: this.intl.t('device.actions.attach-to-vehicle'),
            device,
            selectedVehicle: null,
            confirm: async (modal) => {
                const selectedVehicle = modal.getOption('selectedVehicle');
                if (!selectedVehicle) {
                    return;
                }

                device.setProperties({
                    attachable_uuid: selectedVehicle.id,
                    attachable_type: 'fleet-ops:vehicle',
                });

                modal.startLoading();

                try {
                    await device.save();
                    this.notifications.success(this.intl.t('device.prompts.attach-to-vehicle-success'));
                    await this.hostRouter.refresh();
                    modal.done();
                } catch (error) {
                    device.rollbackAttributes();
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action detachDevice(device) {
        const deviceName = device.displayName ?? device.name ?? device.device_id ?? this.intl.t('resource.device');

        this.modalsManager.confirm({
            title: this.intl.t('device.prompts.detach-telematic-device-title', { deviceName }),
            body: this.intl.t('device.prompts.detach-telematic-device-body', { deviceName }),
            confirm: async (modal) => {
                modal.startLoading();
                device.setProperties({ attachable_uuid: null, attachable_type: null });

                try {
                    await device.save();
                    this.notifications.success(this.intl.t('device.prompts.detach-from-vehicle-success'));
                    await this.hostRouter.refresh();
                    modal.done();
                } catch (error) {
                    device.rollbackAttributes();
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }
}
