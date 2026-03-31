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

    constructor(owner, args) {
        super(owner, args);
        const { resource } = args;
        if (resource?.target_type) {
            this.targetModelName = TYPE_TO_MODEL[resource.target_type] ?? null;
            this.selectedTargetType = this.targetTypeOptions.find((o) => o.value === resource.target_type) ?? null;
        }
        if (resource?.assignee_type) {
            this.assigneeModelName = TYPE_TO_MODEL[resource.assignee_type] ?? null;
            this.selectedAssigneeType = this.assigneeTypeOptions.find((o) => o.value === resource.assignee_type) ?? null;
        }
    }

    /**
     * Handles a change to the target type selector. Resets the target
     * relationship so a stale association is not persisted.
     */
    @action onTargetTypeChange(option) {
        this.selectedTargetType = option;
        this.args.resource.target_type = option.value;
        this.args.resource.target_uuid = null;
        this.args.resource.target = null;
        this.targetModelName = TYPE_TO_MODEL[option.value] ?? null;
    }

    /** Assigns the selected target model to the resource. */
    @action assignTarget(model) {
        this.args.resource.target = model;
        this.args.resource.target_uuid = model?.id ?? null;
    }

    /**
     * Handles a change to the assignee type selector. Resets the assignee
     * relationship so a stale association is not persisted.
     */
    @action onAssigneeTypeChange(option) {
        this.selectedAssigneeType = option;
        this.args.resource.assignee_type = option.value;
        this.args.resource.assignee_uuid = null;
        this.args.resource.assignee = null;
        this.assigneeModelName = TYPE_TO_MODEL[option.value] ?? null;
    }

    /** Assigns the selected assignee model to the resource. */
    @action assignAssignee(model) {
        this.args.resource.assignee = model;
        this.args.resource.assignee_uuid = model?.id ?? null;
    }
}
