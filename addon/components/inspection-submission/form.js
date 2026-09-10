import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { next } from '@ember/runloop';
import { task } from 'ember-concurrency';
import { flattenFields } from '../../utils/inspection-form-structure';
import { valueTypeForFieldType } from '../../utils/inspection-field-types';

const STATUS_OPTIONS = ['draft', 'submitted', 'needs_review', 'resolved'];

/**
 * An inspection being filled in.
 *
 * The header names the form and what is being inspected; the rest is the
 * selected form's field groups, each rendered as a panel of
 * `inspection-field/input`. The answers live here, keyed by field uuid, and
 * are handed up through `@onAnswersChange` as the rows the server accepts —
 * the same `custom_field_values` body the driver API takes, so the console and
 * the app write the same thing and the item results are derived from the
 * pass-fail answers among them.
 *
 * Nothing writes to `@resource` during render: the structure and the stored
 * answers are loaded in tasks, and every value change arrives from an event.
 */
export default class InspectionSubmissionFormComponent extends Component {
    @service inspectionFormActions;
    @service inspectionSubmissionActions;
    @service notifications;

    @tracked groups = [];
    @tracked values = {};

    statusOptions = STATUS_OPTIONS;

    constructor() {
        super(...arguments);
        next(() => {
            if (this.isDestroying || this.isDestroyed) {
                return;
            }

            this.load.perform(this.args.resource?.form);
        });
    }

    get fields() {
        return flattenFields(this.groups);
    }

    get hasStructure() {
        return this.fields.length > 0;
    }

    get failedCount() {
        return this.fields.filter((field) => field.type === 'pass-fail' && this.values[field.uuid]?.passed === false && this.values[field.uuid]?.not_applicable !== true).length;
    }

    get passFailCount() {
        return this.fields.filter((field) => field.type === 'pass-fail').length;
    }

    @task *load(form) {
        this.groups = [];

        if (!form?.id) {
            return;
        }

        try {
            const groups = yield this.inspectionFormActions.loadStructure(form);
            const stored = this.args.resource?.id && !this.args.resource?.isNew ? yield this.inspectionSubmissionActions.loadAnswers(this.args.resource) : {};

            this.groups = groups;
            this.values = this.seed(groups, stored);
            this.announce();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Every field starts with an answer, so a form saved untouched still files
     * a complete set: a pass-fail field passes unless the inspector says
     * otherwise, which is what the first cut did and what the app does.
     */
    seed(groups, stored = {}) {
        return flattenFields(groups).reduce((carry, field) => {
            if (stored[field.uuid] !== undefined) {
                carry[field.uuid] = stored[field.uuid];
                return carry;
            }

            carry[field.uuid] = field.type === 'pass-fail' ? { passed: true, not_applicable: false, severity: null, comments: '', photos: [], unsafe: false } : null;

            return carry;
        }, {});
    }

    /** The answers, as the server accepts them. */
    get rows() {
        return this.fields.map((field) => ({
            custom_field: field.uuid,
            value_type: valueTypeForFieldType(field.type),
            value: this.serializeValue(field, this.values[field.uuid]),
        }));
    }

    /**
     * A file value read back from the server arrives resolved to an object; on
     * the way out it has to be a reference again, which is what the file's
     * public id is — `InspectionFileStore::normalize()` resolves a `file_…` id
     * back to `file:<uuid>`.
     */
    serializeValue(field, value) {
        if (field.type === 'pass-fail') {
            const answer = value && typeof value === 'object' ? value : { passed: true, not_applicable: false };

            return {
                ...answer,
                photos: (Array.isArray(answer.photos) ? answer.photos : []).map((photo) => this.serializeFile(photo)).filter(Boolean),
            };
        }

        if (field.type === 'file-upload' || field.type === 'signature') {
            return this.serializeFile(value);
        }

        return value;
    }

    serializeFile(value) {
        if (value && typeof value === 'object') {
            return value.id ?? null;
        }

        return typeof value === 'string' && value !== '' ? value : null;
    }

    announce() {
        if (typeof this.args.onAnswersChange === 'function') {
            this.args.onAnswersChange(this.rows);
        }
    }

    @action setValue(value, field) {
        this.values = { ...this.values, [field.uuid]: value };

        // A meter field marked as the odometer keeps the submission's own
        // odometer column in step, so the vehicle's reading follows the
        // inspection without the inspector typing it twice.
        if (field.type === 'number' && field.meta?.role === 'odometer' && value !== null && value !== '') {
            this.args.resource.odometer = Number(value);
        }

        this.announce();
    }

    @action assignForm(form) {
        this.args.resource.form = form;
        // The relationship is what the save sends; the column is kept in step
        // with the form's own uuid, never the id the console addresses it by.
        this.args.resource.inspection_form_uuid = form?.uuid ?? form?.id ?? null;
        this.args.resource.type = form?.type || this.args.resource.type || 'dvir';

        return this.load.perform(form);
    }

    @action assignVehicle(vehicle) {
        this.args.resource.vehicle = vehicle;
    }

    @action assignDriver(driver) {
        this.args.resource.driver = driver;
    }

    @action setStatus(status) {
        this.args.resource.status = status;
    }

    @action setOdometer(event) {
        const value = event.target.value;
        this.args.resource.odometer = value === '' ? null : Number(value);
    }

    @action setEngineHours(event) {
        const value = event.target.value;
        this.args.resource.engine_hours = value === '' ? null : Number(value);
    }
}
