import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class TrailerDetailsComponent extends Component {
    @service trailerActions;
    @service vehicleActions;
    @service intl;

    get trailer() {
        return this.args.resource;
    }

    get isAttached() {
        return this.trailer?.isAttached ?? this.trailer?.attachment_state === 'attached';
    }

    get currentVehicle() {
        return this.trailer?.current_vehicle;
    }

    get currentVehicleName() {
        return this.trailer?.current_vehicle_name ?? this.currentVehicle?.displayName ?? this.currentVehicle?.display_name ?? this.currentVehicle?.name;
    }

    get typeLabel() {
        const type = this.trailer?.type;

        return type ? this.intl.t(`trailer.types.${type}`, { default: type }) : null;
    }

    get statusLabel() {
        const status = this.trailer?.status;

        return status ? this.intl.t(`trailer.statuses.${status}`, { default: status }) : null;
    }

    get attachmentLabel() {
        return this.intl.t(`trailer.attachment.${this.isAttached ? 'attached' : 'detached'}`);
    }

    get connectivityLabel() {
        const status = this.trailer?.connectivity_status ?? 'never_connected';

        return this.intl.t(`trailer.connectivity.${status}`, { default: status });
    }

    get ownershipLabel() {
        const value = this.trailer?.ownership_type;

        return value ? this.intl.t(`trailer.ownership-types.${value}`, { default: value }) : null;
    }

    get couplingLabel() {
        const value = this.trailer?.coupling_type;

        return value ? this.intl.t(`trailer.coupling-types.${value}`, { default: value }) : null;
    }

    get brakeLabel() {
        const value = this.trailer?.brake_type;

        return value ? this.intl.t(`trailer.brake-types.${value}`, { default: value }) : null;
    }

    get measurementLabel() {
        const value = this.trailer?.measurement_system;

        return value ? this.intl.t(`trailer.measurement.${value}`, { default: value }) : null;
    }

    get units() {
        const system = this.trailer?.measurement_system === 'imperial' ? 'imperial' : 'metric';

        return {
            length: this.intl.t(`trailer.units.length-${system}`),
            weight: this.intl.t(`trailer.units.weight-${system}`),
            volume: this.intl.t(`trailer.units.volume-${system}`),
            temperature: this.intl.t(`trailer.units.temperature-${system}`),
        };
    }

    get showRefrigeration() {
        return Boolean(this.trailer?.refrigerated) || this.trailer?.type === 'reefer';
    }

    get lastProvider() {
        return this.trailer?.telematics?.last_provider ?? null;
    }

    get lastEventAt() {
        return this.trailer?.telematics?.last_event_at ?? null;
    }

    @action viewVehicle() {
        if (!this.currentVehicle) {
            return;
        }

        if (this.vehicleActions.panel?.view) {
            return this.vehicleActions.panel.view(this.currentVehicle);
        }

        return this.vehicleActions.transition.view(this.currentVehicle);
    }

    @action attachVehicle() {
        return this.trailerActions.attachVehicle(this.trailer);
    }

    @action detachVehicle() {
        return this.trailerActions.detachVehicle(this.trailer);
    }

    @action locate() {
        return this.trailerActions.locate(this.trailer);
    }
}
