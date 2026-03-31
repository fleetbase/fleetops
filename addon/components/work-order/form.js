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

export default class WorkOrderFormComponent extends Component {
    /** Status options for work orders. */
    statusOptions = ['open', 'in_progress', 'on_hold', 'completed', 'cancelled'];

    /** Priority options for work orders. */
    priorityOptions = ['low', 'medium', 'high', 'critical'];

    /** Polymorphic target type options — assets a work order can be raised against. */
    targetTypeOptions = ['fleet-ops:vehicle', 'fleet-ops:driver', 'fleet-ops:equipment'];

    /** Polymorphic assignee type options — who is responsible for the work order. */
    assigneeTypeOptions = ['fleet-ops:driver', 'Auth:User'];

    /** Derived Ember Data model name for the currently selected target type. */
    @tracked targetModelName = null;

    /** Derived Ember Data model name for the currently selected assignee type. */
    @tracked assigneeModelName = null;

    constructor(owner, args) {
        super(owner, args);
        const { resource } = args;
        if (resource?.target_type) {
            this.targetModelName = TYPE_TO_MODEL[resource.target_type] ?? null;
        }
        if (resource?.assignee_type) {
            this.assigneeModelName = TYPE_TO_MODEL[resource.assignee_type] ?? null;
        }
    }

    /**
     * Handles a change to the target type selector. Resets the target
     * relationship so a stale association is not persisted.
     */
    @action onTargetTypeChange(type) {
        this.args.resource.target_type = type;
        this.args.resource.target_uuid = null;
        this.args.resource.target = null;
        this.targetModelName = TYPE_TO_MODEL[type] ?? null;
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
    @action onAssigneeTypeChange(type) {
        this.args.resource.assignee_type = type;
        this.args.resource.assignee_uuid = null;
        this.args.resource.assignee = null;
        this.assigneeModelName = TYPE_TO_MODEL[type] ?? null;
    }

    /** Assigns the selected assignee model to the resource. */
    @action assignAssignee(model) {
        this.args.resource.assignee = model;
        this.args.resource.assignee_uuid = model?.id ?? null;
    }
}
