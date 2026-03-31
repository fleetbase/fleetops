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

const INTERVAL_METHOD_OPTIONS = [
    { label: 'Time-Based (days / weeks / months)', value: 'time' },
    { label: 'Distance-Based (km / miles)', value: 'distance' },
    { label: 'Engine Hours-Based', value: 'engine_hours' },
];

export default class ScheduleFormComponent extends Component {
    @tracked selectedSubjectType = null;
    @tracked selectedAssigneeType = null;
    @tracked subjectModelName = null;
    @tracked assigneeModelName = null;
    @tracked selectedIntervalMethod = null;

    subjectTypeOptions = SUBJECT_TYPE_OPTIONS;
    assigneeTypeOptions = ASSIGNEE_TYPE_OPTIONS;
    intervalMethodOptions = INTERVAL_METHOD_OPTIONS;

    statusOptions = ['active', 'paused', 'completed'];
    priorityOptions = ['low', 'normal', 'high', 'critical'];
    intervalUnitOptions = ['days', 'weeks', 'months', 'years'];
    typeOptions = ['oil_change', 'tire_rotation', 'inspection', 'brake_service', 'air_filter', 'transmission', 'coolant', 'battery', 'other'];

    get isTimeBased() {
        return this.selectedIntervalMethod?.value === 'time';
    }

    get isDistanceBased() {
        return this.selectedIntervalMethod?.value === 'distance';
    }

    get isEngineHoursBased() {
        return this.selectedIntervalMethod?.value === 'engine_hours';
    }

    constructor() {
        super(...arguments);
        const { resource } = this.args;
        if (resource) {
            // Restore subject type selection
            if (resource.subject_type) {
                this.selectedSubjectType = SUBJECT_TYPE_OPTIONS.find((o) => o.value === resource.subject_type) ?? null;
                this.subjectModelName = TYPE_TO_MODEL[resource.subject_type] ?? null;
            }
            // Restore assignee type selection
            if (resource.default_assignee_type) {
                this.selectedAssigneeType = ASSIGNEE_TYPE_OPTIONS.find((o) => o.value === resource.default_assignee_type) ?? null;
                this.assigneeModelName = TYPE_TO_MODEL[resource.default_assignee_type] ?? null;
            }
            // Restore interval method from stored interval_method field, or infer from populated fields
            if (resource.interval_method) {
                this.selectedIntervalMethod = INTERVAL_METHOD_OPTIONS.find((o) => o.value === resource.interval_method) ?? null;
            } else if (resource.interval_distance) {
                this.selectedIntervalMethod = INTERVAL_METHOD_OPTIONS.find((o) => o.value === 'distance') ?? null;
            } else if (resource.interval_engine_hours) {
                this.selectedIntervalMethod = INTERVAL_METHOD_OPTIONS.find((o) => o.value === 'engine_hours') ?? null;
            } else if (resource.interval_value) {
                this.selectedIntervalMethod = INTERVAL_METHOD_OPTIONS.find((o) => o.value === 'time') ?? null;
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

    @action onIntervalMethodChange(option) {
        this.selectedIntervalMethod = option;
        // Store the method on the resource so it round-trips correctly on edit
        this.args.resource.interval_method = option.value;
        // Clear fields from the other methods to avoid stale data being sent
        if (option.value !== 'time') {
            this.args.resource.interval_value = null;
            this.args.resource.interval_unit = null;
            this.args.resource.next_due_date = null;
        }
        if (option.value !== 'distance') {
            this.args.resource.interval_distance = null;
            this.args.resource.next_due_odometer = null;
        }
        if (option.value !== 'engine_hours') {
            this.args.resource.interval_engine_hours = null;
            this.args.resource.next_due_engine_hours = null;
        }
    }
}
