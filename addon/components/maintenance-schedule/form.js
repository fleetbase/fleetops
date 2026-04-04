import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * Maps a polymorphic type string to the Ember Data model name used by ModelSelect.
 */
const TYPE_TO_MODEL = {
    'fleet-ops:vehicle': 'vehicle',
    'fleet-ops:equipment': 'equipment',
    'fleet-ops:vendor': 'vendor',
    'fleet-ops:contact': 'contact',
    'fleet-ops:driver': 'driver',
};

/**
 * Maps a concrete Ember Data model name back to the backend polymorphic type string.
 * Used to restore the type selector when editing an existing record that has a
 * loaded @belongsTo('maintenance-subject', {polymorphic: true}) relationship.
 */
const MODEL_TO_TYPE = {
    'maintenance-subject-vehicle': 'fleet-ops:vehicle',
    'maintenance-subject-equipment': 'fleet-ops:equipment',
    // Fall-through for raw vehicle/equipment models passed from vehicle-actions.js
    vehicle: 'fleet-ops:vehicle',
    equipment: 'fleet-ops:equipment',
    vendor: 'fleet-ops:vendor',
    contact: 'fleet-ops:contact',
    driver: 'fleet-ops:driver',
};

/**
 * Maps a concrete Ember Data model name back to the backend polymorphic type string
 * for the default_assignee relationship.
 */
const ASSIGNEE_MODEL_TO_TYPE = {
    'facilitator-vendor': 'fleet-ops:vendor',
    'facilitator-contact': 'fleet-ops:contact',
    'facilitator-integrated-vendor': 'fleet-ops:vendor',
    vendor: 'fleet-ops:vendor',
    contact: 'fleet-ops:contact',
    driver: 'fleet-ops:driver',
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

export default class MaintenanceScheduleFormComponent extends Component {
    @tracked selectedSubjectType = null;
    @tracked selectedAssigneeType = null;
    @tracked subjectModelName = null;
    @tracked assigneeModelName = null;
    @tracked selectedIntervalMethod = null;
    @tracked reminderOffsets = [];
    @tracked newReminderOffset = '';

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
            // Restore subject type selection from the polymorphic relationship.
            // When editing an existing record, resource.subject is a loaded MaintenanceSubject model.
            // When creating from vehicle-actions.js, resource.subject may be a raw vehicle/equipment model.
            const subject = resource.subject;
            if (subject) {
                const modelName = subject.constructor?.modelName ?? subject.modelName;
                const typeValue = MODEL_TO_TYPE[modelName] ?? null;
                if (typeValue) {
                    this.selectedSubjectType = SUBJECT_TYPE_OPTIONS.find((o) => o.value === typeValue) ?? null;
                    this.subjectModelName = TYPE_TO_MODEL[typeValue] ?? null;
                }
            }
            // Restore assignee type selection from the polymorphic relationship.
            const defaultAssignee = resource.default_assignee;
            if (defaultAssignee) {
                const modelName = defaultAssignee.constructor?.modelName ?? defaultAssignee.modelName;
                const typeValue = ASSIGNEE_MODEL_TO_TYPE[modelName] ?? null;
                if (typeValue) {
                    this.selectedAssigneeType = ASSIGNEE_TYPE_OPTIONS.find((o) => o.value === typeValue) ?? null;
                    this.assigneeModelName = TYPE_TO_MODEL[typeValue] ?? null;
                }
            }
            // Restore reminder_offsets from the resource
            if (Array.isArray(resource.reminder_offsets)) {
                this.reminderOffsets = [...resource.reminder_offsets];
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
        // Clear the subject relationship — user must re-select the asset
        this.args.resource.subject = null;
        this.subjectModelName = TYPE_TO_MODEL[option.value] ?? null;
    }

    @action assignSubject(model) {
        this.args.resource.subject = model;
    }

    @action onAssigneeTypeChange(option) {
        this.selectedAssigneeType = option;
        // Clear the default_assignee relationship — user must re-select
        this.args.resource.default_assignee = null;
        this.assigneeModelName = TYPE_TO_MODEL[option.value] ?? null;
    }

    @action assignDefaultAssignee(model) {
        this.args.resource.default_assignee = model;
    }

    @action addReminderOffset(value) {
        const days = parseInt(value, 10);
        if (!isNaN(days) && days > 0 && !this.reminderOffsets.includes(days)) {
            this.reminderOffsets = [...this.reminderOffsets, days].sort((a, b) => b - a);
            this.args.resource.reminder_offsets = [...this.reminderOffsets];
        }
        this.newReminderOffset = '';
    }

    @action removeReminderOffset(index) {
        const updated = [...this.reminderOffsets];
        updated.splice(index, 1);
        this.reminderOffsets = updated;
        this.args.resource.reminder_offsets = [...this.reminderOffsets];
    }

    @action onReminderOffsetKeydown(event) {
        if (event.key === 'Enter' || event.key === ',') {
            event.preventDefault();
            this.addReminderOffset(this.newReminderOffset);
        }
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
