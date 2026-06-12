import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';
import fleetOpsOptions from '../../../../utils/fleet-ops-options';

export default class ConnectivityTelematicsDetailsDevicesController extends Controller {
    @service deviceActions;
    @service fetch;
    @service hostRouter;
    @service intl;
    @service modalsManager;
    @service notifications;

    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'status', 'provider', 'attachment_state', 'device_id'];
    @tracked telematic;
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-updated_at';
    @tracked query;
    @tracked status;
    @tracked provider;
    @tracked attachment_state;
    @tracked device_id;

    get devices() {
        return Array.from(this.model ?? []);
    }

    get totalDevices() {
        return this.model?.meta?.total ?? this.devices.length;
    }

    get onlineDevicesCount() {
        return this.devices.filter((device) => device.is_online || device.connection_status === 'online' || device.status === 'online' || device.status === 'active').length;
    }

    get unattachedDevicesCount() {
        return this.devices.filter((device) => !device.attachable_uuid).length;
    }

    get offlineDevicesCount() {
        return Math.max(this.devices.length - this.onlineDevicesCount, 0);
    }

    get hasActiveFilters() {
        return Boolean(this.query || this.status || this.provider || this.attachment_state || this.device_id);
    }

    get hasDevices() {
        return this.devices.length > 0;
    }

    get isSyncing() {
        return this.telematic?.status === 'synchronizing' || this.telematic?.meta?.last_sync_result === 'queued';
    }

    get hasSyncRun() {
        const meta = this.telematic?.meta ?? {};

        return Boolean(meta.last_sync_started_at || meta.last_sync_completed_at || meta.last_sync_job_id || meta.last_sync_result || meta.last_sync_total);
    }

    get emptyStateVariant() {
        if (this.hasDevices) {
            return null;
        }

        if (this.hasActiveFilters) {
            return 'filtered_empty';
        }

        if (this.isSyncing) {
            return 'syncing';
        }

        if (!this.hasSyncRun) {
            return 'not_synced';
        }

        return 'empty';
    }

    get emptyStateContent() {
        switch (this.emptyStateVariant) {
            case 'filtered_empty':
                return {
                    tone: 'info',
                    icon: 'filter',
                    title: 'No devices match these filters',
                    message: 'Clear the current search and filters to return to the synced device inventory.',
                    primaryText: 'Clear filters',
                    primaryIcon: 'filter',
                    primaryAction: this.clearFilters,
                };
            case 'syncing':
                return {
                    tone: 'info',
                    icon: 'rotate',
                    title: 'Device sync is running',
                    message: 'Devices are being fetched from the provider. Refresh this tab when the sync job finishes.',
                    primaryText: 'Refresh',
                    primaryIcon: 'refresh',
                    primaryAction: this.refresh,
                };
            case 'not_synced':
                return {
                    tone: 'warning',
                    icon: 'satellite-dish',
                    title: 'No device sync has run yet',
                    message: 'Sync devices from this provider connection to build the inventory and start vehicle attachment.',
                    primaryText: 'Sync Devices',
                    primaryIcon: 'satellite-dish',
                    primaryAction: this.startDeviceSync,
                    secondaryText: 'Test Connection',
                    secondaryIcon: 'plug',
                    secondaryAction: this.openConnectionTestDialog,
                };
            case 'empty':
                return {
                    tone: 'info',
                    icon: 'microchip',
                    title: 'Provider returned no devices',
                    message: 'The connection synced successfully, but the provider did not return device inventory for these credentials.',
                    primaryText: 'Sync Again',
                    primaryIcon: 'refresh',
                    primaryAction: this.startDeviceSync,
                    secondaryText: 'Test Connection',
                    secondaryIcon: 'plug',
                    secondaryAction: this.openConnectionTestDialog,
                    note: 'Check provider permissions and account scope if devices exist in the provider dashboard.',
                };
            default:
                return null;
        }
    }

    get metrics() {
        return [
            { label: 'Synced devices', value: this.totalDevices, icon: 'microchip', accentClass: 'fleetops-connectivity-kpi-accent-blue' },
            { label: 'Online', value: this.onlineDevicesCount, icon: 'signal', accentClass: 'fleetops-connectivity-kpi-accent-green' },
            { label: 'Unattached', value: this.unattachedDevicesCount, icon: 'link-slash', accentClass: 'fleetops-connectivity-kpi-accent-amber' },
            { label: 'Offline / unknown', value: this.offlineDevicesCount, icon: 'power-off', accentClass: 'fleetops-connectivity-kpi-accent-rose' },
        ];
    }

    @tracked actionButtons = [
        {
            icon: 'refresh',
            size: 'xs',
            onClick: this.refresh,
            helpText: this.intl.t('common.refresh'),
            wrapperClass: 'fleetops-telematics-action-button',
        },
        {
            icon: 'ellipsis-h',
            prefix: 'fas',
            text: 'Actions',
            type: 'primary',
            size: 'xs',
            triggerClass: 'fleetops-telematics-action-button',
            items: [
                {
                    icon: 'satellite-dish',
                    text: 'Sync Devices',
                    onClick: this.startDeviceSync,
                },
                {
                    icon: 'link',
                    text: 'Attach Unassigned',
                    onClick: this.openAttachmentsForUnassigned,
                },
            ],
        },
    ];

    @tracked bulkActions = [];

    get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('column.name'),
                valuePath: 'displayName',
                cellComponent: 'table/cell/anchor',
                action: this.deviceActions.transition.view,
                permission: 'fleet-ops view device',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'query',
                filterComponent: 'filter/string',
            },
            {
                label: 'Provider ID',
                valuePath: 'device_id',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'device_id',
                filterComponent: 'filter/string',
            },
            {
                label: 'Vehicle',
                valuePath: 'attached_to_name',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'attachment_state',
                filterComponent: 'filter/multi-option',
                filterOptions: [
                    { label: 'Attached', value: 'attached' },
                    { label: 'Unattached', value: 'unattached' },
                ],
            },
            {
                label: 'Connection',
                valuePath: 'connection_status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'status',
                filterComponent: 'filter/multi-option',
                filterOptions: fleetOpsOptions('deviceStatuses'),
            },
            {
                label: 'Last Seen',
                valuePath: 'lastOnlineAt',
                sortParam: 'last_online_at',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.updated-at'),
                valuePath: 'updatedAt',
                sortParam: 'updated_at',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: true,
                filterComponent: 'filter/date',
            },
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.device') }),
                cellClassNames: 'overflow-visible align-middle',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.device') }),
                        fn: this.deviceActions.transition.view,
                        permission: 'fleet-ops view device',
                    },
                    {
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.device') }),
                        fn: this.deviceActions.transition.edit,
                        permission: 'fleet-ops update device',
                    },
                    {
                        label: 'Attach or change vehicle',
                        fn: this.openAttachDeviceModal,
                        permission: 'fleet-ops update device',
                    },
                    {
                        label: 'Review recent events',
                        fn: this.openDeviceEvents,
                        permission: 'fleet-ops view device-event',
                    },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
    }

    @action refresh() {
        return this.hostRouter.refresh();
    }

    @action clearFilters() {
        this.query = null;
        this.status = null;
        this.provider = null;
        this.attachment_state = null;
        this.device_id = null;
        this.page = 1;
    }

    @action openConnectionTestDialog() {
        this.modalsManager.show('modals/telematic-connection-diagnostics', {
            title: 'Test Connection',
            acceptButtonText: 'Run Test',
            acceptButtonIcon: 'plug',
            declineButtonText: 'Close',
            telematic: this.telematic,
            onTested: () => this.hostRouter.refresh(),
        });
    }

    @action openAttachmentsForUnassigned() {
        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.telematics.details.attachments', this.telematic, {
            queryParams: { attachment_state: 'unattached' },
        });
    }

    @action openDeviceEvents(device) {
        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.telematics.details.events', this.telematic, {
            queryParams: { device_uuid: device.id },
        });
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

    @action startDeviceSync() {
        return this.syncDevices.perform();
    }

    @task *syncDevices() {
        try {
            yield this.fetch.post(`telematics/${this.telematic.id}/discover`);
            this.notifications.success('Device sync queued.');
            yield this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
