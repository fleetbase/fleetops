import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';
import config from 'ember-get-config';
import { task } from 'ember-concurrency';
import { normalizeFieldGroups, flattenFields } from '../utils/inspection-form-structure';
import { answerRows, seedAnswers, summarize } from '../utils/inspection-answers';

/*
 * FleetOps mounts its API at the application root — `fleetops.api.routing.prefix`
 * is null, which is why its consumable routes are `/v1/...` and its internal
 * ones `/int/v1/...` rather than sitting under an engine name the way ledger's
 * do. The public inspection routes follow it, so the namespace here is `public`
 * and not `fleet-ops/public`.
 */
const PUBLIC_NAMESPACE = 'public';

/** The largest photo the link's upload endpoint accepts, matched to the server's limit. */
const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

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
            const response = yield this.fetch.get(`inspections/forms/${this.formId}`, { token: this.token }, { namespace: PUBLIC_NAMESPACE });

            this.form = response?.form;
            this.identity = response?.identity;
            this.groups = normalizeFieldGroups(this.form);
            this.values = seedAnswers(this.groups);
        } catch (error) {
            this.error = yield this.describeFailure(error, 'This inspection could not be loaded.');
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
                { namespace: PUBLIC_NAMESPACE }
            );

            this.submission = response?.submission;
        } catch (error) {
            this.error = yield this.describeFailure(error, 'This inspection could not be submitted.');
        }
    }

    /**
     * Upload a photo or a signature through this link.
     *
     * The console's uploader posts to the platform's file endpoint, which
     * needs a session a link does not have; this posts to the link's own
     * upload endpoint with its token instead, and answers in the shape the
     * sheet expects from the console.
     */
    @action async uploadFile(file, type) {
        if (file?.size > MAX_UPLOAD_BYTES) {
            this.error = 'That photo is larger than 10 MB. Try a smaller one.';
            throw new Error(this.error);
        }

        const url = `${get(config, 'API.host')}/${PUBLIC_NAMESPACE}/inspections/forms/${encodeURIComponent(this.formId)}/files`;

        try {
            const response = await file.upload(url, { data: { token: this.token, type }, headers: { Accept: 'application/json' } });
            const body = await response.json();

            this.error = null;

            return { id: body.file.id, url: body.file.url, filename: body.file.filename };
        } catch (error) {
            this.error = await this.describeFailure(error, 'This photo could not be uploaded.');
            throw error;
        }
    }

    /**
     * What the server said went wrong, in words an inspector can act on. A
     * link that was already used or has expired says so; being rate limited
     * says to wait rather than showing a bare status code.
     */
    async describeFailure(error, fallback) {
        if (error?.status === 429) {
            return 'Too many attempts from this device. Wait a minute and try again.';
        }

        let body = error?.payload ?? null;

        if (!body && typeof error?.json === 'function') {
            body = await error.json().catch(() => null);
        }

        return body?.error ?? body?.errors?.[0] ?? body?.message ?? error?.message ?? fallback;
    }

    @action setValue(value, field) {
        this.values = { ...this.values, [field.uuid]: value };
    }

    @action updateReading(key, event) {
        this[key] = event.target.value;
    }
}
