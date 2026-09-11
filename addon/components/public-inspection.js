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

/** How many digits a link's PIN has, matched to the server. */
const PIN_LENGTH = 6;

/*
 * The fetch service turns a failed response carrying a string `error` into a
 * bare Error of that message, dropping the rest of the body, so the page never
 * saw `pin_required` and showed the PIN prompt as an error with nowhere to
 * type. `rawError` rejects with the body itself.
 */
const REQUEST_OPTIONS = { namespace: PUBLIC_NAMESPACE, rawError: true };

/** Asked for explicitly: without it Laravel answers a refused request with a redirect. */
const JSON_ACCEPT = { Accept: 'application/json' };

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
    @service intl;

    @tracked form = null;
    @tracked identity = null;
    @tracked groups = [];
    @tracked values = {};
    @tracked odometer = '';
    @tracked engineHours = '';
    @tracked signatureName = '';
    @tracked error = null;
    @tracked submission = null;

    /** The PIN given with the link, asked for before the form is shown. */
    @tracked pin = '';
    @tracked pinRequired = false;
    @tracked pinError = null;

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

    get pinIsComplete() {
        return this.pin.length === PIN_LENGTH;
    }

    /**
     * The PIN travels as a header on every request the page makes, so it
     * stays out of the URL and the access logs that record URLs.
     */
    get pinHeaders() {
        return this.pin ? { ...JSON_ACCEPT, 'X-Inspection-Pin': this.pin } : { ...JSON_ACCEPT };
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
            const response = yield this.fetch.get(`inspections/forms/${this.formId}`, { token: this.token }, { ...REQUEST_OPTIONS, headers: this.pinHeaders });

            this.pinRequired = false;
            this.pinError = null;
            this.form = response?.form;
            this.identity = response?.identity;
            this.groups = normalizeFieldGroups(this.form);
            this.values = seedAnswers(this.groups);
        } catch (error) {
            const body = yield this.failureBody(error);

            // The link wants its PIN, or the one given was wrong: ask again,
            // saying how many tries are left. Anything else, including a link
            // locked by too many wrong PINs, is the page's error.
            if (body?.pin_required) {
                this.pinRequired = true;
                this.pinError = this.pin ? this.intl.t('inspection.public.pin-wrong', { count: body.attempts_left ?? 0 }) : null;
                this.pin = '';
                return;
            }

            this.pinRequired = false;
            this.error = yield this.describeFailure(error, 'This inspection could not be loaded.', body);
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
                { ...REQUEST_OPTIONS, headers: this.pinHeaders }
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
            const response = await file.upload(url, { data: { token: this.token, type }, headers: { Accept: 'application/json', ...this.pinHeaders } });
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
    async describeFailure(error, fallback, body = null) {
        body = body ?? (await this.failureBody(error));

        // Laravel's throttle answers with this message and nothing else.
        if (error?.status === 429 || body?.message === 'Too Many Attempts.') {
            return 'Too many attempts from this device. Wait a minute and try again.';
        }

        const message = body?.error ?? body?.errors?.[0] ?? body?.message ?? error?.message;

        return typeof message === 'string' && message ? message : fallback;
    }

    /**
     * The JSON the server answered a failed request with. A `rawError` request
     * rejects with that body itself; an upload rejects with the response, whose
     * body can be read only once.
     */
    async failureBody(error) {
        if (!error) {
            return null;
        }

        if (error.payload) {
            return error.payload;
        }

        if (typeof error.json === 'function') {
            return await error.json().catch(() => null);
        }

        return error instanceof Error ? null : error;
    }

    /** Digits only, and no more than a PIN has: pasted spaces or dashes are dropped. */
    @action updatePin(event) {
        this.pin = String(event.target.value ?? '')
            .replace(/\D/g, '')
            .slice(0, PIN_LENGTH);
        this.pinError = null;
    }

    @action submitPin() {
        if (this.pinIsComplete && !this.loadInspection.isRunning) {
            this.loadInspection.perform();
        }
    }

    @action pinKeydown(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            this.submitPin();
        }
    }

    @action setValue(value, field) {
        this.values = { ...this.values, [field.uuid]: value };
    }

    @action updateReading(key, event) {
        this[key] = event.target.value;
    }
}
