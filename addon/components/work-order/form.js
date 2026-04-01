import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * Maps a user-facing polymorphic type string to the Ember Data model name
 * used by ModelSelect.
 */
const TYPE_TO_MODEL = {
    'fleet-ops:vehicle': 'vehicle',
    'fleet-ops:equipment': 'equipment',
    'fleet-ops:vendor': 'vendor',
    'fleet-ops:contact': 'contact',
    'Auth:User': 'user',
};

/**
 * Maps a concrete Ember Data model name back to the backend polymorphic type string.
 * Used to restore the type selector when editing an existing record that has a
 * loaded @belongsTo('maintenance-subject', {polymorphic: true}) relationship.
 */
const TARGET_MODEL_TO_TYPE = {
    'maintenance-subject-vehicle': 'fleet-ops:vehicle',
    'maintenance-subject-equipment': 'fleet-ops:equipment',
    // Fall-through for raw models passed from vehicle-actions.js
    vehicle: 'fleet-ops:vehicle',
    equipment: 'fleet-ops:equipment',
};

/**
 * Maps a concrete Ember Data model name back to the backend polymorphic type string
 * for the assignee relationship.
 */
const ASSIGNEE_MODEL_TO_TYPE = {
    'facilitator-vendor': 'fleet-ops:vendor',
    'facilitator-contact': 'fleet-ops:contact',
    'facilitator-integrated-vendor': 'fleet-ops:vendor',
    vendor: 'fleet-ops:vendor',
    contact: 'fleet-ops:contact',
    user: 'Auth:User',
};

export default class WorkOrderFormComponent extends Component {
    /** Status options for work orders. */
    statusOptions = ['open', 'in_progress', 'on_hold', 'completed', 'cancelled'];

    /** Priority options for work orders. */
    priorityOptions = ['low', 'medium', 'high', 'critical'];

    /**
     * Polymorphic target type options — the asset a work order is raised against.
     */
    targetTypeOptions = [
        { value: 'fleet-ops:vehicle', label: 'Vehicle' },
        { value: 'fleet-ops:equipment', label: 'Equipment' },
    ];

    /**
     * Polymorphic assignee type options — who is responsible for completing
     * the work order.
     */
    assigneeTypeOptions = [
        { value: 'fleet-ops:vendor', label: 'Vendor' },
        { value: 'fleet-ops:contact', label: 'Contact' },
        { value: 'Auth:User', label: 'User' },
    ];

    /** Derived Ember Data model name for the currently selected target type. */
    @tracked targetModelName = null;

    /** Derived Ember Data model name for the currently selected assignee type. */
    @tracked assigneeModelName = null;

    /** The currently selected target type option object (drives the PowerSelect trigger label). */
    @tracked selectedTargetType = null;

    /** The currently selected assignee type option object. */
    @tracked selectedAssigneeType = null;

    /**
     * Completion data fields — only used when status is being set to 'closed'.
     * These are pure UI state; they are never persisted directly. Instead, the
     * controller reads them via the @onCompletionChange callback and passes them
     * to workOrderActions.prepareForSave() before calling workOrder.save().
     */
    @tracked completionOdometer = null;
    @tracked completionEngineHours = null;
    @tracked completionLaborCost = null;
    @tracked completionPartsCost = null;
    @tracked completionTax = null;
    @tracked completionNotes = null;

    /**
     * Returns true when the work order status is set to 'closed', which
     * reveals the Completion Details panel.
     */
    get isCompleting() {
        return this.args.resource?.status === 'closed';
    }

    constructor(owner, args) {
        super(owner, args);
        const { resource } = args;

        // Restore target type selection from the polymorphic relationship.
        const target = resource?.target;
        if (target) {
            const modelName = target.constructor?.modelName ?? target.modelName;
            const typeValue = TARGET_MODEL_TO_TYPE[modelName] ?? null;
            if (typeValue) {
                this.targetModelName = TYPE_TO_MODEL[typeValue] ?? null;
                this.selectedTargetType = this.targetTypeOptions.find((o) => o.value === typeValue) ?? null;
            }
        }

        // Restore assignee type selection from the polymorphic relationship.
        const assignee = resource?.assignee;
        if (assignee) {
            const modelName = assignee.constructor?.modelName ?? assignee.modelName;
            const typeValue = ASSIGNEE_MODEL_TO_TYPE[modelName] ?? null;
            if (typeValue) {
                this.assigneeModelName = TYPE_TO_MODEL[typeValue] ?? null;
                this.selectedAssigneeType = this.assigneeTypeOptions.find((o) => o.value === typeValue) ?? null;
            }
        }
    }

    /**
     * Handles a change to the target type selector. Resets the target
     * relationship so a stale association is not persisted.
     */
    @action onTargetTypeChange(option) {
        this.selectedTargetType = option;
        this.args.resource.target = null;
        this.targetModelName = TYPE_TO_MODEL[option.value] ?? null;
    }

    /** Assigns the selected target model to the resource. */
    @action assignTarget(model) {
        this.args.resource.target = model;
    }

    /**
     * Handles a change to the assignee type selector. Resets the assignee
     * relationship so a stale association is not persisted.
     */
    @action onAssigneeTypeChange(option) {
        this.selectedAssigneeType = option;
        this.args.resource.assignee = null;
        this.assigneeModelName = TYPE_TO_MODEL[option.value] ?? null;
    }

    /** Assigns the selected assignee model to the resource. */
    @action assignAssignee(model) {
        this.args.resource.assignee = model;
    }

    /**
     * Notifies the parent (controller) of the current completion field values
     * whenever any completion input changes. The controller stores this plain
     * object and passes it to workOrderActions.prepareForSave() before saving —
     * no component reference is ever held by the controller.
     */
    _notifyCompletionChange() {
        if (typeof this.args.onCompletionChange === 'function') {
            this.args.onCompletionChange({
                odometer: this.completionOdometer,
                engineHours: this.completionEngineHours,
                laborCost: this.completionLaborCost,
                partsCost: this.completionPartsCost,
                tax: this.completionTax,
                notes: this.completionNotes,
            });
        }
    }

    @action setCompletionOdometer(value) {
        this.completionOdometer = value;
        this._notifyCompletionChange();
    }

    @action setCompletionEngineHours(value) {
        this.completionEngineHours = value;
        this._notifyCompletionChange();
    }

    @action setCompletionLaborCost(value) {
        this.completionLaborCost = value;
        this._notifyCompletionChange();
    }

    @action setCompletionPartsCost(value) {
        this.completionPartsCost = value;
        this._notifyCompletionChange();
    }

    @action setCompletionTax(value) {
        this.completionTax = value;
        this._notifyCompletionChange();
    }

    @action setCompletionNotes(value) {
        this.completionNotes = value;
        this._notifyCompletionChange();
    }
}
