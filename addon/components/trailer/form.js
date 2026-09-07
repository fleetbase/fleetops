import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * Stable trailer classification values. Mirrors `Trailer::TYPES` on the server.
 */
export const TRAILER_TYPES = [
    'dry_van',
    'reefer',
    'flatbed',
    'step_deck',
    'lowboy',
    'tanker',
    'bulk',
    'dump',
    'chassis',
    'curtain_side',
    'car_carrier',
    'livestock',
    'logging',
    'dolly',
    'specialty',
    'other',
];

/**
 * Lifecycle statuses. Mirrors `Trailer::STATUSES` on the server.
 */
export const TRAILER_STATUSES = ['available', 'in_use', 'maintenance', 'out_of_service', 'retired'];

/**
 * Ownership arrangements. Mirrors `Trailer::OWNERSHIP_TYPES` on the server.
 */
export const TRAILER_OWNERSHIP_TYPES = ['owned', 'leased', 'financed', 'rented'];

export const TRAILER_COUPLING_TYPES = ['fifth_wheel', 'pintle_hook', 'ball_hitch', 'gooseneck', 'drawbar', 'other'];
export const TRAILER_BRAKE_TYPES = ['air', 'electric', 'hydraulic_surge', 'none', 'other'];
export const TRAILER_MEASUREMENT_SYSTEMS = ['metric', 'imperial'];
export const TRAILER_ODOMETER_UNITS = ['km', 'mi'];

export default class TrailerFormComponent extends Component {
    @service fetch;
    @service currentUser;
    @service notifications;
    @service intl;

    get typeOptions() {
        return TRAILER_TYPES.map((value) => ({ value, label: this.intl.t(`trailer.types.${value}`) }));
    }

    get statusOptions() {
        return TRAILER_STATUSES.map((value) => ({ value, label: this.intl.t(`trailer.statuses.${value}`), description: this.intl.t(`trailer.status-descriptions.${value}`) }));
    }

    get measurementOptions() {
        return TRAILER_MEASUREMENT_SYSTEMS.map((value) => ({
            value,
            label: this.intl.t(`trailer.measurement.${value}`),
            description: this.intl.t(`trailer.measurement-descriptions.${value}`),
        }));
    }

    get odometerUnitOptions() {
        return TRAILER_ODOMETER_UNITS.map((value) => ({ value, label: this.intl.t(`trailer.odometer-units.${value}`) }));
    }

    get couplingOptions() {
        return TRAILER_COUPLING_TYPES.map((value) => ({ value, label: this.intl.t(`trailer.coupling-types.${value}`) }));
    }

    get brakeOptions() {
        return TRAILER_BRAKE_TYPES.map((value) => ({ value, label: this.intl.t(`trailer.brake-types.${value}`) }));
    }

    get ownershipOptions() {
        return TRAILER_OWNERSHIP_TYPES.map((value) => ({ value, label: this.intl.t(`trailer.ownership-types.${value}`) }));
    }

    /**
     * Unit suffixes shown next to dimensional inputs, derived from the selected
     * measurement system so operators always know which unit a value is stored in.
     */
    get units() {
        const system = this.args.resource?.measurement_system === 'imperial' ? 'imperial' : 'metric';

        return {
            length: this.intl.t(`trailer.units.length-${system}`),
            weight: this.intl.t(`trailer.units.weight-${system}`),
            volume: this.intl.t(`trailer.units.volume-${system}`),
            temperature: this.intl.t(`trailer.units.temperature-${system}`),
        };
    }

    /**
     * Reefer settings only make sense for temperature-controlled trailers.
     */
    get showRefrigeration() {
        const resource = this.args.resource;

        return Boolean(resource?.refrigerated) || resource?.type === 'reefer';
    }

    /**
     * Lease expiry is only meaningful when the trailer is not owned outright.
     */
    get showLeaseExpiry() {
        return ['leased', 'rented', 'financed'].includes(this.args.resource?.ownership_type);
    }

    get currency() {
        return this.args.resource?.currency || this.currentUser?.company?.currency || this.currentUser?.currency || 'USD';
    }

    @action setType(option) {
        this.args.resource.type = option?.value ?? null;

        if (option?.value === 'reefer' && this.args.resource.refrigerated !== true) {
            this.args.resource.refrigerated = true;
        }
    }

    @action toggleRefrigerated(value) {
        this.args.resource.refrigerated = Boolean(value);
    }

    @action setVendor(vendor) {
        this.args.resource.vendor = vendor;
        this.args.resource.vendor_uuid = vendor?.id ?? null;
    }

    @action setWarranty(warranty) {
        this.args.resource.warranty = warranty;
        this.args.resource.warranty_uuid = warranty?.id ?? null;
    }

    @action setCategory(category) {
        this.args.resource.category = category;
        this.args.resource.category_uuid = category?.id ?? null;
    }

    @task *handlePhotoUpload(file) {
        try {
            yield this.fetch.uploadFile.perform(
                file,
                {
                    path: `uploads/${this.currentUser.companyId}/trailers/${this.args.resource.id}`,
                    subject_uuid: this.args.resource.id,
                    subject_type: 'fleet-ops:trailer',
                    type: 'trailer_photo',
                },
                (uploadedFile) => {
                    this.args.resource.setProperties({
                        photo_uuid: uploadedFile.id,
                        photo_url: uploadedFile.url,
                        photo: uploadedFile,
                    });
                }
            );
        } catch (error) {
            this.notifications.error(this.intl.t('trailer.prompts.photo-upload-error', { message: error.message }));
        }
    }
}
