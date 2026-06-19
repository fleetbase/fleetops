import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class DevicePanelTabsVehicleComponent extends Component {
    @service deviceActions;
    @service hostRouter;
    @service mapManager;
    @service vehicleActions;

    get device() {
        return this.args.resource ?? this.args.model;
    }

    get vehicle() {
        return this.device?.attachable;
    }

    get vehicleName() {
        return this.device?.attached_to_name ?? this.vehicle?.displayName ?? this.vehicle?.display_name ?? this.vehicle?.name;
    }

    get vehicleSubtitle() {
        return this.vehicle?.plate_number ?? this.vehicle?.call_sign ?? this.vehicle?.vin ?? this.vehicle?.public_id ?? this.device?.attachable_uuid;
    }

    get vehiclePhotoUrl() {
        return this.vehicle?.photo_url ?? this.vehicle?.avatar_url;
    }

    get vehicleStatus() {
        return this.vehicle?.status ?? (this.vehicle?.online ? 'online' : null);
    }

    get vehicleDriverName() {
        return this.vehicle?.driver?.displayName ?? this.vehicle?.driver?.display_name ?? this.vehicle?.driver?.name ?? this.vehicle?.driver_name;
    }

    get hasVehicle() {
        return Boolean(this.vehicleName || this.device?.attachable_uuid);
    }

    get canOpenVehicle() {
        return Boolean(this.vehicle?.id);
    }

    get canLocateVehicle() {
        return Boolean(this.vehicle?.id && (this.vehicle?.location || this.vehicle?.last_position));
    }

    @action attachToVehicle() {
        return this.deviceActions.attachToVehicle(this.device, { callback: () => this.device?.reload?.() });
    }

    @action detachFromVehicle() {
        return this.deviceActions.detachFromVehicle(this.device, { callback: () => this.device?.reload?.() });
    }

    @action openVehicle() {
        if (this.vehicle?.id) {
            return this.vehicleActions.panel?.view
                ? this.vehicleActions.panel.view(this.vehicle)
                : this.hostRouter.transitionTo('console.fleet-ops.management.vehicles.index.details', this.vehicle);
        }
    }

    @action async locateVehicle() {
        if (!this.vehicle?.id) {
            return;
        }

        await this.transitionToLiveMap();
        await this.mapManager.waitForMap({ timeoutMs: 8000 });

        this.mapManager.focusResource(this.vehicle, 16, {
            paddingBottomRight: [300, 200],
            moveend: () => {
                this.vehicleActions.panel?.view?.(this.vehicle, { closeOnTransition: true });
            },
        });
    }

    async transitionToLiveMap() {
        try {
            await this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index', { queryParams: { layout: 'map' } });
        } catch (_) {
            // Keep locate usable if another transition is already active.
        }
    }
}
