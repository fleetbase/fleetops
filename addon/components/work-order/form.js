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
 * loaded @belongsTo('work-order-target', {polymorphic: true}) relationship.
 */
const TARGET_MODEL_TO_TYPE = {
    'work-order-target-vehicle': 'fleet-ops:vehicle',
    'work-order-target-equipment': 'fleet-ops:equipment',
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
     * Each entry has a `value` (stored on the model) and a `label` (displayed in the UI).
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

    /** Completion data fields — only used when status is being set to closed. */
    @tracked completionOdometer = null;
    @tracked completionEngineHours = null;
    @tracked completionLaborCost = null;
    @tracked completionPartsCost = null;
    @tracked completionTax = null;
    @tracked completionNotes = null;

    /**
     * Returns true when the work order status is set to 'closed', which
     * reveals the Completion Details panel and seeds the auto-generated
     * Maintenance History record via the WorkOrderObserver on save.
     */
    get isCompleting() {
        return this.args.resource?.status === 'closed';
    }

    constructor(owner, args) {
        super(owner, args);
        const { resource } = args;
        // Restore target type selection from the polymorphic relationship.
        // When editing an existing record, resource.target is a loaded WorkOrderTarget model.
        // When creating from vehicle-actions.js, resource.target may be a raw vehicle/equipment model.
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
        // Clear the target relationship — user must re-select the asset
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
        // Clear the assignee relationship — user must re-select
        this.args.resource.assignee = null;
        this.assigneeModelName = TYPE_TO_MODEL[option.value] ?? null;
    }

    /** Assigns the selected assignee model to the resource. */
    @action assignAssignee(model) {
        this.args.resource.assignee = model;
    }

    /**
     * Packs the completion data fields into @resource.meta.completion_data
     * so the WorkOrderObserver can read them when the record is saved.
     * Called by the controller's save task before workOrder.save().
     */
    @action prepareForSave() {
        if (!this.isCompleting) {
            return;
        }
        const resource = this.args.resource;
        const existing = resource.meta ?? {};
        const laborCost = parseFloat(this.completionLaborCost) || 0;
        const partsCost = parseFloat(this.completionPartsCost) || 0;
        const tax = parseFloat(this.completionTax) || 0;
        resource.meta = {
            ...existing,
            completion_data: {
                odometer: this.completionOdometer ? parseFloat(this.completionOdometer) : null,
                engine_hours: this.completionEngineHours ? parseFloat(this.completionEngineHours) : null,
                labor_cost: laborCost || null,
                parts_cost: partsCost || null,
                tax: tax || null,
                total_cost: (laborCost + partsCost + tax) || null,
                currency: resource.currency ?? 'USD',
                notes: this.completionNotes ?? null,
            },
        };
    }

    /**
     * Registers this component instance with the parent controller so the
     * controller's save task can call prepareForSave() before persisting.
     */
    @action registerWithController() {
        if (typeof this.args.onRegisterForm === 'function') {
            this.args.onRegisterForm(this);
        }
    }
}
