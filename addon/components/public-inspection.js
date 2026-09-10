import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { normalizeFieldGroups, flattenFields } from '../utils/inspection-form-structure';
import { answerRows, seedAnswers, summarize } from '../utils/inspection-answers';

/**
 * An inspection filled in from a tokenised link, outside the console.
 *
 * Registered into the `auth:login` menu registry as the hidden slug
 * `inspection`, which the host console's top-level `virtual` route resolves at
 * `/~/inspection` — a sibling of `console`, so none of the console's chrome or
 * its authentication gate applies. The link itself carries the form and the
 * token as query parameters.
 *
 * The sheet is the same `inspection-sheet` the console renders: whoever built
 * the form sees it laid out the way they built it, whether it is being
 * answered by a manager at a desk or a contractor on a phone.
 */
export default class PublicInspectionComponent extends Component {
    @service urlSearchParams;
    @service fetch;

    @tracked form = null;
    @tracked identity = null;
    @tracked groups = [];
    @tracked values = {};
    @tracked odometer = '';
    @tracked engineHours = '';
    @tracked signatureName = '';
    @tracked error = null;
    @tracked submission = null;

    constructor() {
        super(...arguments);
        this.loadInspection.perform();
    }

    get formId() {
        return this.urlSearchParams.get('id');
    }

    get token() {
        return this.urlSearchParams.get('token');
    }

    get fields() {
        return flattenFields(this.groups);
    }

    get summary() {
        return summarize(this.fields, this.values);
    }

    get hasSheet() {
        return this.fields.length > 0;
    }

    /**
     * What still stops this being submitted, said plainly rather than by
     * greying out a button with no explanation.
     */
    get blockedReason() {
        const { missingRequired, incompleteDefects } = this.summary;

        if (missingRequired) {
            return 'required';
        }

        return incompleteDefects ? 'defects' : null;
    }

    get canSubmit() {
        return this.hasSheet && !this.blockedReason && !this.submitInspection.isRunning && !this.submission;
    }

    @task({ restartable: true })
    *loadInspection() {
        this.error = null;

        if (!this.formId || !this.token) {
            this.error = 'This inspection link is missing its form or its token.';
            return;
        }

        try {
            const response = yield this.fetch.get(`inspections/forms/${this.formId}`, { token: this.token }, { namespace: 'fleet-ops/public' });

            this.form = response?.form;
            this.identity = response?.identity;
            this.groups = normalizeFieldGroups(this.form);
            this.values = seedAnswers(this.groups);
        } catch (error) {
            this.error = error?.payload?.error ?? error?.message ?? 'This inspection could not be loaded.';
        }
    }

    @task({ drop: true })
    *submitInspection() {
        this.error = null;

        try {
            const response = yield this.fetch.post(
                `inspections/forms/${this.formId}/submit`,
                {
                    token: this.token,
                    odometer: this.odometer === '' ? null : parseInt(this.odometer, 10),
                    engine_hours: this.engineHours === '' ? null : parseInt(this.engineHours, 10),
                    signature: this.signatureName ? { name: this.signatureName, signed_at: new Date().toISOString() } : null,
                    custom_field_values: answerRows(this.fields, this.values),
                },
                { namespace: 'fleet-ops/public' }
            );

            this.submission = response?.submission;
        } catch (error) {
            this.error = error?.payload?.error ?? error?.message ?? 'This inspection could not be submitted.';
        }
    }

    @action setValue(value, field) {
        this.values = { ...this.values, [field.uuid]: value };
    }

    @action updateReading(key, event) {
        this[key] = event.target.value;
    }
}
