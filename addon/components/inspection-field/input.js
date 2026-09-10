import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { INSPECTION_SEVERITIES } from '../../utils/inspection-field-types';
import { answerState, isUnsafeAnswer } from '../../utils/inspection-answers';

const PASS_FAIL_DEFAULT = { passed: true, not_applicable: false, severity: null, comments: '', photos: [], unsafe: false };

/**
 * One inspection field, being answered.
 *
 * Every one of the eleven field types is rendered here rather than some being
 * handed to the platform's `custom-field/input`. Delegating made the sheet
 * read as two different forms interleaved — its own label chrome, its own
 * spacing, its own idea of what a control looks like — and an inspection is
 * one list that an inspector reads straight down. Owning them all is what
 * makes every row the same shape.
 *
 * The component owns no copy of the answer. `@value` in, `@onChange` out —
 * the answering screen holds the values, so nothing is written during render.
 */
export default class InspectionFieldInputComponent extends Component {
    @service fetch;
    @service intl;
    @tracked uploadProgress = null;

    /** Freshly uploaded files, so a photo can be shown before it is saved. */
    @tracked previews = {};

    severityOptions = INSPECTION_SEVERITIES;

    get field() {
        return this.args.field ?? {};
    }

    get meta() {
        const meta = this.field.meta;
        return meta && typeof meta === 'object' ? meta : {};
    }

    /** A field with no label still needs something to click on. */
    get label() {
        return this.field.label || this.field.name || this.intl.t('inspection.builder.untitled-field');
    }

    get instructions() {
        return this.meta.instructions ?? null;
    }

    get unit() {
        return this.meta.unit ?? null;
    }

    /**
     * A control that needs the full width sits under its label instead of
     * beside it: a note, a photo, a signature.
     */
    get isStacked() {
        return ['textarea', 'file-upload', 'signature'].includes(this.field.type);
    }

    /** What this row currently says, for the row's own `data-answer`. */
    get answerState() {
        return answerState(this.field, this.args.value);
    }

    /**
     * What an empty control should suggest. An author can write their own; a
     * number otherwise shows a zero rather than nothing at all, which is what
     * an inspector reaches for on a tread depth or a pressure.
     */
    get placeholder() {
        if (this.meta.placeholder) {
            return this.meta.placeholder;
        }

        switch (this.field.type) {
            case 'number':
                return '0';
            case 'select':
                return this.intl.t('inspection.answer.select-placeholder');
            case 'textarea':
                return this.intl.t('inspection.answer.note-placeholder');
            default:
                return this.intl.t('inspection.answer.text-placeholder');
        }
    }

    /**
     * Whether this row may offer an upload.
     *
     * A public link runs unauthenticated, and the file endpoint the uploader
     * posts to does not. So a link renders the field and says the photo has
     * to come from the console or the driver app, rather than showing a
     * button that can only fail.
     */
    get canUpload() {
        return this.args.allowUploads !== false && !this.args.disabled;
    }

    get uploadsBlocked() {
        return this.args.allowUploads === false;
    }

    /** The answers a `select` or `radio-button` field offers. */
    get choiceOptions() {
        const options = this.field.options;
        return Array.isArray(options) && options.length ? options : null;
    }

    // ---------- pass-fail ----------

    get answer() {
        const value = this.args.value;
        if (value && typeof value === 'object' && !Array.isArray(value)) {
            return { ...PASS_FAIL_DEFAULT, ...value, photos: Array.isArray(value.photos) ? value.photos : [] };
        }

        if (typeof value === 'boolean') {
            return { ...PASS_FAIL_DEFAULT, passed: value };
        }

        return { ...PASS_FAIL_DEFAULT };
    }

    get isPassed() {
        const answer = this.answer;
        return answer.passed === true && answer.not_applicable !== true;
    }

    get isFailed() {
        const answer = this.answer;
        return answer.passed === false && answer.not_applicable !== true;
    }

    get isNotApplicable() {
        return this.answer.not_applicable === true;
    }

    get severity() {
        return this.answer.severity ?? this.meta.severity ?? 'medium';
    }

    get isUnsafe() {
        return isUnsafeAnswer(this.field, this.args.value);
    }

    get comments() {
        return this.answer.comments ?? '';
    }

    /** The photos on a failed pass-fail answer, ready to render. */
    get answerPhotos() {
        return this.answer.photos.map((photo) => this.#describeFile(photo));
    }

    get requiresComment() {
        return this.isFailed && this.meta.require_comment_on_fail === true;
    }

    get requiresPhoto() {
        return this.isFailed && this.meta.require_photo_on_fail === true;
    }

    // ---------- file / signature ----------

    get file() {
        const value = this.args.value;
        if (!value) {
            return null;
        }

        return this.#describeFile(value);
    }

    get booleanValue() {
        const value = this.args.value;
        if (typeof value === 'boolean') {
            return value;
        }

        return value === 'true' || value === 1 || value === '1';
    }

    // ---------- actions ----------

    emit(value) {
        if (typeof this.args.onChange === 'function') {
            this.args.onChange(value, this.field);
        }
    }

    @action setText(event) {
        this.emit(event.target.value);
    }

    @action setNumber(event) {
        const value = event.target.value;
        this.emit(value === '' ? null : Number(value));
    }

    @action setBoolean(value) {
        this.emit(Boolean(value));
    }

    @action setChoice(option) {
        this.emit(option ?? null);
    }

    @action markPassed() {
        this.emit({ ...this.answer, passed: true, not_applicable: false, severity: null, unsafe: false });
    }

    @action markFailed() {
        this.emit({
            ...this.answer,
            passed: false,
            not_applicable: false,
            severity: this.answer.severity ?? this.meta.severity ?? 'medium',
            unsafe: this.answer.unsafe ?? Boolean(this.meta.unsafe_on_fail),
        });
    }

    @action markNotApplicable() {
        this.emit({ ...this.answer, passed: true, not_applicable: true, severity: null, unsafe: false });
    }

    @action setSeverity(severity) {
        this.emit({ ...this.answer, severity });
    }

    @action setUnsafe(unsafe) {
        this.emit({ ...this.answer, unsafe: Boolean(unsafe) });
    }

    @action setComments(event) {
        this.emit({ ...this.answer, comments: event.target.value });
    }

    @action removePhoto(index) {
        this.emit({ ...this.answer, photos: this.answer.photos.filter((_, photoIndex) => photoIndex !== index) });
    }

    @action clearFile() {
        this.emit(null);
    }

    /**
     * A photo or signature picked in the console is uploaded straight away and
     * the answer keeps `file:<uuid>`, the platform's own convention. The
     * server claims any file referenced this way when the submission is saved
     * (`InspectionFileStore::attachReferenced`).
     */
    @action addPhoto(file) {
        return this.#upload(file, 'inspection_photo', (uploaded) => {
            this.emit({ ...this.answer, photos: [...this.answer.photos, `file:${uploaded.id}`] });
        });
    }

    @action setFile(file) {
        const type = this.field.type === 'signature' ? 'inspection_signature' : 'inspection_photo';

        return this.#upload(file, type, (uploaded) => {
            this.emit(`file:${uploaded.id}`);
        });
    }

    #upload(file, type, onUploaded) {
        if (['queued', 'failed', 'timed_out', 'aborted'].indexOf(file.state) === -1) {
            return;
        }

        this.uploadProgress = file;

        return this.fetch.uploadFile.perform(
            file,
            {
                path: `uploads/inspections/${this.field.uuid ?? 'field'}`,
                type,
                ...this.#subjectParams(),
            },
            (uploaded) => {
                this.uploadProgress = null;
                this.previews = { ...this.previews, [`file:${uploaded.id}`]: { url: uploaded.url, filename: uploaded.original_filename ?? uploaded.filename } };
                onUploaded(uploaded);
            },
            () => {
                this.uploadProgress = null;
                if (file.queue && typeof file.queue.remove === 'function') {
                    file.queue.remove(file);
                }
            }
        );
    }

    #subjectParams() {
        const subject = this.args.subject;
        const subjectUuid = subject?.uuid ?? subject?.id;
        if (!subjectUuid || subject.isNew) {
            return {};
        }

        return { subject_uuid: subjectUuid, subject_type: 'fleet-ops:inspection-submission' };
    }

    /**
     * A file value in one shape, whichever way it arrived: a `file:<uuid>`
     * reference just uploaded here, or the `{ id, url, filename }` the
     * submission resource resolves a stored reference to.
     */
    #describeFile(value) {
        if (value && typeof value === 'object') {
            return { reference: value.id, url: value.url, filename: value.filename, contentType: value.content_type };
        }

        if (typeof value !== 'string' || value === '') {
            return { reference: null, url: null, filename: null, contentType: null };
        }

        const preview = this.previews[value];

        return {
            reference: value,
            url: preview?.url ?? (value.startsWith('http') ? value : null),
            filename: preview?.filename ?? null,
            contentType: null,
        };
    }
}
