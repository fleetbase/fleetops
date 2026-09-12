import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { INSPECTION_SEVERITIES } from '../../utils/inspection-field-types';
import { answerState, isUnsafeAnswer, isBlank, defectSummary, ROOMY_FIELD_TYPES } from '../../utils/inspection-answers';

/** The date-ish types that are still one compact control. */
const INPUT_TYPES = { 'date-picker': 'date', 'date-time-input': 'datetime-local' };

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

    get field() {
        return this.args.field ?? {};
    }

    get meta() {
        const meta = this.field.meta;
        return meta && typeof meta === 'object' ? meta : {};
    }

    /** A failed check, which owes a severity, and maybe a comment and photos. */
    get isDefect() {
        return this.field.type === 'pass-fail' && this.answerState === 'fail';
    }

    /** This field's flyout is the one open on the sheet — only ever one is. */
    get isFlyoutOpen() {
        return this.isDefect && Boolean(this.field.uuid) && this.args.openFieldId === this.field.uuid;
    }

    /** What the failure has recorded, for the chip it leaves in the cell. */
    get defect() {
        return defectSummary(this.field, this.args.value);
    }

    get severityLabel() {
        const severity = this.defect.severity;

        if (!severity) {
            return null;
        }

        return INSPECTION_SEVERITIES.includes(severity) ? this.intl.t(`inspection.severity.${severity}`) : severity;
    }

    /** What a closed failure still owes, said on its chip in amber. */
    get defectStatus() {
        const { needsComment, needsPhoto } = this.defect;

        if (needsComment && needsPhoto) {
            return this.intl.t('inspection.defect.needs-both');
        }

        if (needsComment) {
            return this.intl.t('inspection.defect.needs-comment');
        }

        return needsPhoto ? this.intl.t('inspection.defect.needs-photo') : null;
    }

    get flyoutTitle() {
        return this.intl.t('inspection.flyout.title', { label: this.label });
    }

    /** A note, an upload or a signature — never a column, whatever the answer. */
    get isRoomy() {
        return ROOMY_FIELD_TYPES.includes(this.field.type);
    }

    /** A note puts its control under the label; a file puts it beside. */
    get isStackedBand() {
        return this.field.type === 'textarea';
    }

    get isTargeted() {
        return Boolean(this.field.uuid) && this.args.targetId === this.field.uuid;
    }

    /** A required answer still missing, which its own edge says in amber. */
    get isOutstanding() {
        return Boolean(this.field.required) && isBlank(this.field, this.args.value);
    }

    get passFailOptions() {
        return [
            { value: 'pass', label: this.intl.t('inspection.answer.pass') },
            { value: 'fail', label: this.intl.t('inspection.answer.fail') },
            { value: 'na', label: this.intl.t('inspection.answer.not-applicable') },
        ];
    }

    get severityOptions() {
        return INSPECTION_SEVERITIES.map((severity) => ({
            value: severity,
            label: this.intl.t(`inspection.severity.${severity}`),
        }));
    }

    get inputType() {
        return INPUT_TYPES[this.field.type] ?? 'text';
    }

    get fileIcon() {
        return this.field.type === 'signature' ? 'signature' : 'upload';
    }

    get uploadLabel() {
        return this.field.type === 'signature' ? this.intl.t('inspection.answer.upload-signature') : this.intl.t('inspection.answer.upload-photo');
    }

    get emptyFileNote() {
        return this.field.type === 'signature' ? this.intl.t('inspection.answer.no-signature') : this.intl.t('inspection.answer.no-photo');
    }

    /** What this failure still owes, said once beside the photo slots. */
    get defectRequirement() {
        if (this.requiresComment && this.requiresPhoto) {
            return this.intl.t('inspection.answer.comment-and-photo-required');
        }

        if (this.requiresPhoto) {
            return this.intl.t('inspection.answer.photo-required');
        }

        return this.requiresComment ? this.intl.t('inspection.answer.comment-required') : null;
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
        return this.isDefect && this.meta.require_comment_on_fail === true;
    }

    get requiresPhoto() {
        return this.isDefect && this.meta.require_photo_on_fail === true;
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

    /**
     * One of pass, fail or n/a. Failing seeds the severity and the unsafe flag
     * from what the field's author set as its default, so the common case is
     * already answered; passing or marking n/a clears both, because a check
     * that did not fail cannot carry a severity.
     */
    @action setPassFail(choice) {
        if (choice === 'fail') {
            this.emit({
                ...this.answer,
                passed: false,
                not_applicable: false,
                severity: this.answer.severity ?? this.meta.severity ?? 'medium',
                unsafe: this.answer.unsafe ?? Boolean(this.meta.unsafe_on_fail),
            });

            // Choosing Fail already means "record a defect": no second click.
            this.openFlyout();

            return;
        }

        // The comment and photos survive a switch away, so an accidental Pass
        // followed by Fail again brings them back.
        this.emit({
            ...this.answer,
            passed: true,
            not_applicable: choice === 'na',
            severity: null,
            unsafe: false,
        });

        if (typeof this.args.onCloseFlyout === 'function') {
            this.args.onCloseFlyout(this.field);
        }
    }

    @action openFlyout() {
        if (typeof this.args.onOpenFlyout === 'function') {
            this.args.onOpenFlyout(this.field);
        }
    }

    /**
     * Close this field's flyout. Focus goes back to its Fail button when the
     * inspector closed it themselves, but not when they pressed somewhere
     * else on the page — their attention is already there.
     */
    @action closeFlyout(reason) {
        if (typeof this.args.onCloseFlyout === 'function') {
            this.args.onCloseFlyout(this.field);
        }

        if (reason === 'outside') {
            return;
        }

        document.querySelector(`#inspection-field-${this.field.uuid} [data-answer="fail"]`)?.focus({ preventScroll: true });
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

        const done = (uploaded) => {
            this.uploadProgress = null;
            this.previews = { ...this.previews, [`file:${uploaded.id}`]: { url: uploaded.url, filename: uploaded.original_filename ?? uploaded.filename } };
            onUploaded(uploaded);
        };

        const failed = () => {
            this.uploadProgress = null;

            if (file.queue && typeof file.queue.remove === 'function') {
                file.queue.remove(file);
            }
        };

        // A public link has no session to upload with, so it hands in an
        // uploader of its own that posts through the link's token. It answers
        // in the same shape, so nothing after this point knows the difference.
        if (typeof this.args.uploader === 'function') {
            return Promise.resolve()
                .then(() => this.args.uploader(file, type))
                .then(done, failed);
        }

        return this.fetch.uploadFile.perform(
            file,
            {
                path: `uploads/inspections/${this.field.uuid ?? 'field'}`,
                type,
                ...this.#subjectParams(),
            },
            done,
            failed
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
