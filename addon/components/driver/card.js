import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { get } from '@ember/object';
import config from 'ember-get-config';

const STATUS_TONES = {
    available: 'text-green-500',
    active: 'text-green-500',
    on_duty: 'text-green-500',
    busy: 'text-yellow-500',
    assigned: 'text-yellow-500',
    unavailable: 'text-gray-400',
    offline: 'text-gray-400',
    suspended: 'text-red-500',
};

export default class DriverCardComponent extends Component {
    @service driverActions;

    get resource() {
        return this.args.resource;
    }

    get fallbackImage() {
        return config?.defaultValues?.driverImage;
    }

    get statusValue() {
        return get(this.resource, 'status') ?? '';
    }

    get statusLabel() {
        return this.statusValue || '-';
    }

    get statusToneClass() {
        return STATUS_TONES[this.statusValue] ?? STATUS_TONES[String(this.statusValue).toLowerCase()] ?? 'text-gray-400';
    }

    get assignedVehicleLabel() {
        const driver = this.resource;

        return get(driver, 'vehicle_assigned.display_name') ?? get(driver, 'vehicle.display_name') ?? get(driver, 'vehicle_name');
    }
}
