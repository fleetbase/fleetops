import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * Maps a user-facing polymorphic type string to the Ember Data model name
 * used by ModelSelect.
 */
const TYPE_TO_MODEL = {
    'fleet-ops:vehicle': 'vehicle',
    'fleet-ops:driver': 'driver',
    'fleet-ops:equipment': 'equipment',
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

    /** Polymorphic maintainable type options — the asset being maintained. */
    maintainableTypeOptions = ['fleet-ops:vehicle', 'fleet-ops:equipment'];

    /** Polymorphic performed-by type options — who carried out the maintenance. */
    performedByTypeOptions = ['fleet-ops:driver', 'Auth:User'];

    /** Derived Ember Data model name for the currently selected maintainable type. */
    @tracked maintainableModelName = null;

    /** Derived Ember Data model name for the currently selected performed-by type. */
    @tracked performedByModelName = null;

    constructor(owner, args) {
        super(owner, args);
        const { resource } = args;
        if (resource?.maintainable_type) {
            this.maintainableModelName = TYPE_TO_MODEL[resource.maintainable_type] ?? null;
        }
        if (resource?.performed_by_type) {
            this.performedByModelName = TYPE_TO_MODEL[resource.performed_by_type] ?? null;
        }
    }

    /**
     * Handles a change to the maintainable type selector. Resets the
     * maintainable relationship so a stale association is not persisted.
     */
    @action onMaintainableTypeChange(type) {
        this.args.resource.maintainable_type = type;
        this.args.resource.maintainable_uuid = null;
        this.args.resource.maintainable = null;
        this.maintainableModelName = TYPE_TO_MODEL[type] ?? null;
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
    @action onPerformedByTypeChange(type) {
        this.args.resource.performed_by_type = type;
        this.args.resource.performed_by_uuid = null;
        this.args.resource.performedBy = null;
        this.performedByModelName = TYPE_TO_MODEL[type] ?? null;
    }

    /** Assigns the selected performer model to the resource. */
    @action assignPerformedBy(model) {
        this.args.resource.performedBy = model;
        this.args.resource.performed_by_uuid = model?.id ?? null;
    }
}
