import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class ConnectivityDevicesIndexDetailsVehicleController extends Controller {
    @service deviceActions;
    @service hostRouter;
    @service intl;

    @tracked queryParams = [];

    get device() {
        return this.model;
    }

    get vehicle() {
        return this.device?.attachable;
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
}
