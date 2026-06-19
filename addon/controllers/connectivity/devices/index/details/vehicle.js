import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class ConnectivityDevicesIndexDetailsVehicleController extends Controller {
    @service deviceActions;
    @service hostRouter;
    @service intl;
    @service mapManager;
    @service vehicleActions;

    @tracked queryParams = [];

    get device() {
        return this.model?.device ?? this.model;
    }

    get vehicle() {
        return this.device?.attachable;
    }

    get positions() {
        return Array.from(this.model?.positions ?? []);
    }

    get hasPositions() {
        return this.positions.length > 0;
    }

    get vehicleName() {
        return this.device?.attached_to_name ?? this.vehicle?.displayName ?? this.vehicle?.display_name ?? this.vehicle?.name;
    }

    get vehicleSubtitle() {
        return this.vehicle?.plate_number ?? this.vehicle?.vin ?? this.vehicle?.public_id ?? this.device?.attachable_uuid;
    }

    get vehiclePhotoUrl() {
        return this.vehicle?.photo_url ?? this.vehicle?.avatar_url;
    }

    get vehicleStatus() {
        return this.vehicle?.status ?? (this.vehicle?.online ? 'online' : null);
    }

    get vehicleDriverName() {
        return this.vehicle?.driver?.name ?? this.vehicle?.driver_name;
    }

    get plateNumber() {
        return this.vehicle?.plate_number;
    }

    get vin() {
        return this.vehicle?.vin;
    }

    get callSign() {
        return this.vehicle?.call_sign;
    }

    get yearMakeModel() {
        return this.vehicle?.yearMakeModel ?? [this.vehicle?.year, this.vehicle?.make, this.vehicle?.model].filter(Boolean).join(' ');
    }

    get hasVehicle() {
        return Boolean(this.vehicleName || this.device?.attachable_uuid);
    }

    get canOpenVehicle() {
        return Boolean(this.vehicle?.id);
    }

    get canLocateVehicle() {
        return Boolean(this.vehicle?.id && (this.vehicle?.location || this.vehicle?.last_position || this.hasPositions));
    }

    @action attachToVehicle() {
        return this.deviceActions.attachToVehicle(this.device, { callback: () => this.hostRouter.refresh() });
    }

    @action detachFromVehicle() {
        return this.deviceActions.detachFromVehicle(this.device, { callback: () => this.hostRouter.refresh() });
    }

    @action openVehicle() {
        if (this.vehicle?.id) {
            return this.hostRouter.transitionTo('console.fleet-ops.management.vehicles.index.details', this.vehicle);
        }
    }

    @action openVehiclePositions() {
        if (this.vehicle?.id) {
            return this.hostRouter.transitionTo('console.fleet-ops.management.vehicles.index.details.positions', this.vehicle);
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
            // Keep locate actions usable even if the current transition is already in-flight.
        }
    }
}
