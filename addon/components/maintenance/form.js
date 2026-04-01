import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * Maps a polymorphic type value to the Ember Data model name used by ModelSelect.
 */
const TYPE_TO_MODEL = {
    'fleet-ops:vehicle': 'vehicle',
    'fleet-ops:driver': 'driver',
    'fleet-ops:equipment': 'equipment',
    'fleet-ops:vendor': 'vendor',
    'Auth:User': 'user',
};

/**
 * Maps a concrete Ember Data model name back to the backend polymorphic type string.
 * Used to restore the type selector when editing an existing record that has a
 * loaded @belongsTo('maintenance-subject', {polymorphic: true}) relationship.
 */
const MAINTAINABLE_MODEL_TO_TYPE = {
    'maintenance-subject-vehicle': 'fleet-ops:vehicle',
    'maintenance-subject-equipment': 'fleet-ops:equipment',
    // Fall-through for raw models passed from vehicle-actions.js
    vehicle: 'fleet-ops:vehicle',
    equipment: 'fleet-ops:equipment',
};

/**
 * Maps a concrete Ember Data model name back to the backend polymorphic type string
 * for the performed_by relationship.
 */
const PERFORMED_BY_MODEL_TO_TYPE = {
    'facilitator-vendor': 'fleet-ops:vendor',
    'facilitator-contact': 'fleet-ops:contact',
    'facilitator-integrated-vendor': 'fleet-ops:vendor',
    vendor: 'fleet-ops:vendor',
    driver: 'fleet-ops:driver',
    user: 'Auth:User',
};

export default class MaintenanceFormComponent extends Component {
    /** Maintenance type options — the category of maintenance activity. */
    maintenanceTypeOptions = ['preventive', 'corrective', 'predictive', 'routine', 'emergency', 'inspection', 'repair', 'replacement', 'calibration'];

    /** Status options for a maintenance record. */
    statusOptions = ['scheduled', 'in_progress', 'completed', 'cancelled', 'on_hold'];

    /** Priority options for a maintenance record. */
    priorityOptions = ['low', 'medium', 'high', 'critical'];

    /**
     * Polymorphic maintainable type options — the asset being maintained.
     * Uses label/value objects so the PowerSelect trigger shows a human-readable
     * label instead of the raw model type string.
     */
    maintainableTypeOptions = [
        { value: 'fleet-ops:vehicle', label: 'Vehicle' },
        { value: 'fleet-ops:equipment', label: 'Equipment' },
    ];

    /**
     * Polymorphic performed-by type options — who carried out the maintenance.
     * Uses label/value objects so the PowerSelect trigger shows a human-readable
     * label instead of the raw model type string.
     */
    performedByTypeOptions = [
        { value: 'fleet-ops:vendor', label: 'Vendor' },
        { value: 'fleet-ops:driver', label: 'Driver' },
        { value: 'Auth:User', label: 'User' },
    ];

    /** The currently selected maintainable type option object. */
    @tracked selectedMaintainableType = null;

    /** The currently selected performed-by type option object. */
    @tracked selectedPerformedByType = null;

    /** Derived Ember Data model name for the currently selected maintainable type. */
    @tracked maintainableModelName = null;

    /** Derived Ember Data model name for the currently selected performed-by type. */
    @tracked performedByModelName = null;

    constructor(owner, args) {
        super(owner, args);
        const { resource } = args;
        // Restore maintainable type selection from the polymorphic relationship.
        // When editing an existing record, resource.maintainable is a loaded MaintenanceSubject model.
        // When creating from vehicle-actions.js, resource.maintainable may be a raw vehicle/equipment model.
        const maintainable = resource?.maintainable;
        if (maintainable) {
            const modelName = maintainable.constructor?.modelName ?? maintainable.modelName;
            const typeValue = MAINTAINABLE_MODEL_TO_TYPE[modelName] ?? null;
            if (typeValue) {
                this.selectedMaintainableType = this.maintainableTypeOptions.find((o) => o.value === typeValue) ?? null;
                this.maintainableModelName = TYPE_TO_MODEL[typeValue] ?? null;
            }
        }
        // Restore performed_by type selection from the polymorphic relationship.
        const performedBy = resource?.performed_by;
        if (performedBy) {
            const modelName = performedBy.constructor?.modelName ?? performedBy.modelName;
            const typeValue = PERFORMED_BY_MODEL_TO_TYPE[modelName] ?? null;
            if (typeValue) {
                this.selectedPerformedByType = this.performedByTypeOptions.find((o) => o.value === typeValue) ?? null;
                this.performedByModelName = TYPE_TO_MODEL[typeValue] ?? null;
            }
        }
    }

    /**
     * Handles a change to the maintainable type selector. Resets the
     * maintainable relationship so a stale association is not persisted.
     */
    @action onMaintainableTypeChange(option) {
        this.selectedMaintainableType = option;
        // Clear the maintainable relationship — user must re-select the asset
        this.args.resource.maintainable = null;
        this.maintainableModelName = TYPE_TO_MODEL[option?.value] ?? null;
    }

    /** Assigns the selected maintainable model to the resource. */
    @action assignMaintainable(model) {
        this.args.resource.maintainable = model;
    }

    /**
     * Handles a change to the performed-by type selector. Resets the
     * performed-by relationship so a stale association is not persisted.
     */
    @action onPerformedByTypeChange(option) {
        this.selectedPerformedByType = option;
        // Clear the performed_by relationship — user must re-select
        this.args.resource.performed_by = null;
        this.performedByModelName = TYPE_TO_MODEL[option?.value] ?? null;
    }

    /** Assigns the selected performer model to the resource. */
    @action assignPerformedBy(model) {
        this.args.resource.performed_by = model;
    }
}
