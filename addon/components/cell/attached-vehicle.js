import Component from '@glimmer/component';
import { action } from '@ember/object';

export default class CellAttachedVehicleComponent extends Component {
    get device() {
        return this.args.row;
    }

    get vehicle() {
        return this.device?.attachable;
    }

    get vehicleResource() {
        return (
            this.vehicle ?? {
                id: this.device?.attachable_uuid,
                displayName: this.device?.attached_to_name,
                display_name: this.device?.attached_to_name,
                name: this.device?.attached_to_name,
                public_id: this.device?.attachable_uuid,
            }
        );
    }

    get identityColumn() {
        return {
            ...(this.args.column ?? {}),
            action: null,
            showStatusBadge: false,
        };
    }

    get hasVehicle() {
        return Boolean(this.device?.attachable_uuid && this.isVehicleAttachment);
    }

    get isVehicleAttachment() {
        const attachableType = `${this.device?.attachable_type ?? ''}`.toLowerCase();

        return !attachableType || attachableType.includes('vehicle');
    }

    @action onClick(_vehicle, event) {
        const { column, onClick } = this.args;

        if (!this.hasVehicle) {
            return;
        }

        if (typeof onClick === 'function') {
            onClick(this.device, event);
        }

        if (typeof column?.action === 'function') {
            column.action(this.device, event);
        }
    }
}
