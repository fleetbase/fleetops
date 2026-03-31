import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * Maps a polymorphic type string to the Ember Data model name used by ModelSelect.
 * Uses colon separator to match the convention in work-order/form.js.
 */
const TYPE_TO_MODEL = {
    'fleet-ops:vehicle': 'vehicle',
    'fleet-ops:equipment': 'equipment',
    'fleet-ops:vendor': 'vendor',
    'fleet-ops:contact': 'contact',
    'fleet-ops:driver': 'driver',
};

const SUBJECT_TYPE_OPTIONS = [
    { label: 'Vehicle', value: 'fleet-ops:vehicle' },
    { label: 'Equipment', value: 'fleet-ops:equipment' },
];

const ASSIGNEE_TYPE_OPTIONS = [
    { label: 'Vendor', value: 'fleet-ops:vendor' },
    { label: 'Contact', value: 'fleet-ops:contact' },
    { label: 'Driver', value: 'fleet-ops:driver' },
];

export default class ScheduleFormComponent extends Component {
    @tracked selectedSubjectType = null;
    @tracked selectedAssigneeType = null;
    @tracked subjectModelName = null;
    @tracked assigneeModelName = null;

    subjectTypeOptions = SUBJECT_TYPE_OPTIONS;
    assigneeTypeOptions = ASSIGNEE_TYPE_OPTIONS;

    statusOptions = ['active', 'paused', 'completed'];
    priorityOptions = ['low', 'normal', 'high', 'critical'];
    intervalUnitOptions = ['days', 'weeks', 'months', 'years'];
    typeOptions = ['oil_change', 'tire_rotation', 'inspection', 'brake_service', 'air_filter', 'transmission', 'coolant', 'battery', 'other'];

    constructor() {
        super(...arguments);
        const { resource } = this.args;
        if (resource) {
            if (resource.subject_type) {
                this.selectedSubjectType = SUBJECT_TYPE_OPTIONS.find((o) => o.value === resource.subject_type) ?? null;
                this.subjectModelName = TYPE_TO_MODEL[resource.subject_type] ?? null;
            }
            if (resource.default_assignee_type) {
                this.selectedAssigneeType = ASSIGNEE_TYPE_OPTIONS.find((o) => o.value === resource.default_assignee_type) ?? null;
                this.assigneeModelName = TYPE_TO_MODEL[resource.default_assignee_type] ?? null;
            }
        }
    }

    @action onSubjectTypeChange(option) {
        this.selectedSubjectType = option;
        this.args.resource.subject_type = option.value;
        this.args.resource.subject_uuid = null;
        this.args.resource.subject = null;
        this.subjectModelName = TYPE_TO_MODEL[option.value] ?? null;
    }

    @action assignSubject(model) {
        this.args.resource.subject = model;
        this.args.resource.subject_uuid = model?.id ?? null;
    }

    @action onAssigneeTypeChange(option) {
        this.selectedAssigneeType = option;
        this.args.resource.default_assignee_type = option.value;
        this.args.resource.default_assignee_uuid = null;
        this.args.resource.defaultAssignee = null;
        this.assigneeModelName = TYPE_TO_MODEL[option.value] ?? null;
    }

    @action assignDefaultAssignee(model) {
        this.args.resource.defaultAssignee = model;
        this.args.resource.default_assignee_uuid = model?.id ?? null;
    }
}
