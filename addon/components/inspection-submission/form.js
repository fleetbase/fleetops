import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { next } from '@ember/runloop';
import { task } from 'ember-concurrency';
import { flattenFields } from '../../utils/inspection-form-structure';
import { answerRows, seedAnswers } from '../../utils/inspection-answers';

const STATUS_OPTIONS = ['draft', 'submitted', 'needs_review', 'resolved'];

/**
 * An inspection being filled in.
 *
 * The details of the inspection come first, then the selected form's field
 * groups as an `inspection-sheet` — the same sheet a public link renders, so
 * the two cannot drift. The answers live here, keyed by field uuid, and are
 * handed up through `@onAnswersChange` as the rows the server accepts — the
 * same `custom_field_values` body the driver API takes, so the console, a
 * link and the app all write the same thing and the item results are derived
 * from the pass-fail answers among them.
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

    @task *load(form) {
        this.groups = [];

        if (!form?.id) {
            return;
        }

        try {
            const groups = yield this.inspectionFormActions.loadStructure(form);
            const stored = this.args.resource?.id && !this.args.resource?.isNew ? yield this.inspectionSubmissionActions.loadAnswers(this.args.resource) : {};

            this.groups = groups;
            this.values = seedAnswers(groups, stored);
            this.announce();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /** The answers, as the server accepts them. */
    get rows() {
        return answerRows(this.fields, this.values);
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

    @action setStatus(option) {
        this.args.resource.status = option?.value ?? null;
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
