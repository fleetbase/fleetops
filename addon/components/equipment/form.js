import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * Maps a user-facing polymorphic type string to the Ember Data model name
 * used by ModelSelect.
 */
const TYPE_TO_MODEL = {
    'fleet-ops:vehicle': 'vehicle',
    'fleet-ops:driver': 'driver',
    'fleet-ops:equipment': 'equipment',
};

export default class EquipmentFormComponent extends Component {
    @service fetch;
    @service currentUser;
    @service notifications;

    /** Equipment type options. */
    equipmentTypeOptions = ['ppe', 'refrigeration_unit', 'tool', 'liftgate', 'ramp', 'container', 'pallet_jack', 'forklift', 'safety_equipment', 'communication_device', 'other'];

    /** Status options for equipment. */
    statusOptions = ['available', 'in_use', 'maintenance', 'retired', 'lost', 'damaged'];

    /**
     * Polymorphic equipable type options — the asset this equipment is attached to.
     * Each entry has a `value` (stored on the model) and a `label` (displayed in the UI).
     */
    equipableTypeOptions = [
        { value: 'fleet-ops:vehicle', label: 'Vehicle' },
        { value: 'fleet-ops:driver', label: 'Driver' },
    ];

    /** Derived Ember Data model name for the currently selected equipable type. */
    @tracked equipableModelName = null;

    /** The currently selected equipable type option object (drives the PowerSelect trigger label). */
    @tracked selectedEquipableType = null;

    constructor(owner, args) {
        super(owner, args);
        const { resource } = args;
        if (resource?.equipable_type) {
            this.equipableModelName = TYPE_TO_MODEL[resource.equipable_type] ?? null;
            this.selectedEquipableType = this.equipableTypeOptions.find((o) => o.value === resource.equipable_type) ?? null;
        }
    }

    /**
     * Handles a change to the equipable type selector. Resets the equipable
     * relationship so a stale association is not persisted.
     */
    @action onEquipableTypeChange(option) {
        this.selectedEquipableType = option;
        this.args.resource.equipable_type = option.value;
        this.args.resource.equipable_uuid = null;
        this.args.resource.equipable = null;
        this.equipableModelName = TYPE_TO_MODEL[option.value] ?? null;
    }

    /** Assigns the selected equipable model to the resource. */
    @action assignEquipable(model) {
        this.args.resource.equipable = model;
        this.args.resource.equipable_uuid = model?.id ?? null;
    }

    /**
     * Handles photo upload using the Fleetbase fetch service upload pattern.
     */
    @task *handlePhotoUpload(file) {
        try {
            yield this.fetch.uploadFile.perform(
                file,
                {
                    path: `uploads/${this.currentUser.companyId}/equipment/${this.args.resource.id}`,
                    subject_uuid: this.args.resource.id,
                    subject_type: 'fleet-ops:equipment',
                    type: 'equipment_photo',
                },
                (uploadedFile) => {
                    this.args.resource.setProperties({
                        photo_uuid: uploadedFile.id,
                        photo_url: uploadedFile.url,
                        photo: uploadedFile,
                    });
                }
            );
        } catch (err) {
            this.notifications.error('Unable to upload photo: ' + err.message);
        }
    }
}
