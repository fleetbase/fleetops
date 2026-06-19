import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import buildDeviceTableColumns from '../../../utils/device-table-columns';
import fleetOpsOptions from '../../../utils/fleet-ops-options';

export default class ConnectivityDevicesIndexController extends Controller {
    @service deviceActions;
    @service hostRouter;
    @service telematicActions;
    @service intl;
    @service mapManager;
    @service notifications;
    @service store;
    @service vehicleActions;

    /** query params */
    @tracked queryParams = [
        'name',
        'status',
        'attachment_state',
        'telematic',
        'provider',
        'vehicle',
        'connection_status',
        'device_id',
        'type',
        'serial_number',
        'last_online_at',
        'page',
        'limit',
        'sort',
        'query',
        'public_id',
        'created_at',
        'updated_at',
    ];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked name;
    @tracked status;
    @tracked attachment_state;
    @tracked telematic;
    @tracked provider;
    @tracked vehicle;
    @tracked connection_status;
    @tracked device_id;
    @tracked type;
    @tracked serial_number;
    @tracked last_online_at;

    /** action buttons */
    @tracked actionButtons = [
        {
            icon: 'refresh',
            onClick: this.deviceActions.refresh,
            helpText: this.intl.t('common.refresh'),
        },
        {
            text: this.intl.t('common.new'),
            type: 'primary',
            icon: 'plus',
            onClick: this.deviceActions.transition.create,
        },
        {
            text: this.intl.t('common.import'),
            type: 'magic',
            icon: 'upload',
            onClick: this.deviceActions.import,
        },
        {
            text: this.intl.t('common.export'),
            icon: 'long-arrow-up',
            iconClass: 'rotate-icon-45',
            wrapperClass: 'hidden md:flex',
            onClick: this.deviceActions.export,
        },
    ];

    /** bulk action buttons */
    @tracked bulkActions = [
        {
            label: 'Delete selected...',
            class: 'text-red-500',
            fn: this.deviceActions.bulkDelete,
        },
    ];

    get deviceTypeOptions() {
        return fleetOpsOptions('deviceTypes');
    }

    get deviceStatusOptions() {
        return fleetOpsOptions('deviceStatuses');
    }

    get columns() {
        const columns = buildDeviceTableColumns(this, { deviceActionMode: 'route', showDeviceStatus: false });
        const actionsColumn = columns.find((column) => column.cellComponent === 'table/cell/dropdown');

        actionsColumn?.actions.push(
            {
                separator: true,
            },
            {
                label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.device') }),
                fn: this.deviceActions.delete,
                permission: 'fleet-ops delete device',
            }
        );

        return columns;
    }

    @action openTelematic(telematic) {
        if (telematic?.id) {
            return this.telematicActions.transition.view(telematic);
        }
    }

    @action hasAttachedVehicle(device) {
        return Boolean(device?.attachable_uuid && this.isVehicleAttachment(device));
    }

    @action async viewAttachedVehicle(device) {
        const vehicle = await this.resolveAttachedVehicle(device);

        if (!vehicle) {
            return;
        }

        if (this.vehicleActions.panel?.view) {
            return this.vehicleActions.panel.view(vehicle);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.management.vehicles.index.details', vehicle);
    }

    @action async locateAttachedVehicle(device) {
        const vehicle = await this.resolveAttachedVehicle(device);

        if (!vehicle) {
            return;
        }

        await this.transitionToLiveMap();
        await this.mapManager.waitForMap({ timeoutMs: 8000 });

        this.mapManager.focusResource(vehicle, 16, {
            paddingBottomRight: [300, 200],
            moveend: () => {
                this.vehicleActions.panel?.view?.(vehicle, { closeOnTransition: true });
            },
        });
    }

    async transitionToLiveMap() {
        try {
            await this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index', { queryParams: { layout: 'map' } });
        } catch (_) {
            // Keep locate actions usable even if another map transition is already active.
        }
    }

    isVehicleAttachment(device) {
        const attachableType = `${device?.attachable_type ?? ''}`.toLowerCase();

        return !attachableType || attachableType.includes('vehicle');
    }

    async resolveAttachedVehicle(device) {
        if (!device?.attachable_uuid || !this.isVehicleAttachment(device)) {
            return null;
        }

        const attachable = device.attachable;

        if (attachable && typeof attachable.then !== 'function') {
            return attachable;
        }

        if (attachable && typeof attachable.then === 'function') {
            return await attachable;
        }

        const cachedVehicle = this.store.peekRecord('vehicle', device.attachable_uuid);

        if (cachedVehicle) {
            return cachedVehicle;
        }

        try {
            return await this.store.findRecord('vehicle', device.attachable_uuid);
        } catch (error) {
            this.notifications.serverError(error);
        }

        return null;
    }
}
