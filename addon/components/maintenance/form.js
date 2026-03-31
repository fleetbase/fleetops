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

export default class MaintenanceFormComponent extends Component {
    /** Maintenance type options — the category of maintenance activity. */
    maintenanceTypeOptions = [
        'preventive',
        'corrective',
        'predictive',
        'routine',
        'emergency',
        'inspection',
        'repair',
        'replacement',
        'calibration',
    ];

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
        if (resource?.maintainable_type) {
            this.selectedMaintainableType = this.maintainableTypeOptions.find((o) => o.value === resource.maintainable_type) ?? null;
            this.maintainableModelName = TYPE_TO_MODEL[resource.maintainable_type] ?? null;
        }
        if (resource?.performed_by_type) {
            this.selectedPerformedByType = this.performedByTypeOptions.find((o) => o.value === resource.performed_by_type) ?? null;
            this.performedByModelName = TYPE_TO_MODEL[resource.performed_by_type] ?? null;
        }
    }

    /**
     * Handles a change to the maintainable type selector. Resets the
     * maintainable relationship so a stale association is not persisted.
     */
    @action onMaintainableTypeChange(option) {
        this.selectedMaintainableType = option;
        this.args.resource.maintainable_type = option?.value ?? null;
        this.args.resource.maintainable_uuid = null;
        this.args.resource.maintainable = null;
        this.maintainableModelName = TYPE_TO_MODEL[option?.value] ?? null;
    }

    /** Assigns the selected maintainable model to the resource. */
    @action assignMaintainable(model) {
        this.args.resource.maintainable = model;
        this.args.resource.maintainable_uuid = model?.id ?? null;
    }

    /**
     * Handles a change to the performed-by type selector. Resets the
     * performed-by relationship so a stale association is not persisted.
     */
    @action onPerformedByTypeChange(option) {
        this.selectedPerformedByType = option;
        this.args.resource.performed_by_type = option?.value ?? null;
        this.args.resource.performed_by_uuid = null;
        this.args.resource.performedBy = null;
        this.performedByModelName = TYPE_TO_MODEL[option?.value] ?? null;
    }

    /** Assigns the selected performer model to the resource. */
    @action assignPerformedBy(model) {
        this.args.resource.performedBy = model;
        this.args.resource.performed_by_uuid = model?.id ?? null;
    }
}
