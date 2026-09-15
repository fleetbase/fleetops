import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { INSPECTION_FIELD_TYPES, INSPECTION_SEVERITIES, componentForFieldType, isOptionFieldType } from '../../utils/inspection-field-types';

/**
 * The editor for one inspection field.
 *
 * It is the platform's custom-field editor plus what an inspection needs: the
 * eleven types an inspection form may be built from, and — only for
 * `pass-fail` — the *On fail* rules the driver app enforces and the server
 * re-checks (`InspectionSubmitter::normalizeValue()`).
 *
 * The field is a plain object owned by the builder's draft, not an Ember Data
 * record: a form is laid out before the form record exists and the whole
 * structure is written on the first save. Nothing here mutates `@field` — each
 * change builds a new object and hands it to `@onChange`, so no write ever
 * happens during render.
 */
export default class InspectionFieldFormComponent extends Component {
    @tracked newOption = '';

    fieldTypes = INSPECTION_FIELD_TYPES;
    severityOptions = INSPECTION_SEVERITIES;
    colSpanOptions = [1, 2, 3];

    /**
     * The field being edited, held locally so an edit re-renders.
     *
     * Invoked directly by the builder the field arrives as `@field`; rendered
     * inside the resource context panel it arrives on the overlay
     * definition's shared `state`, which is a plain object — mutating it
     * would never re-render, so the component owns a tracked copy and writes
     * through on every change. That shared handle is what the builder reads
     * back when the author saves.
     */
    @tracked localField = this.args.field ?? this.args.overlay?.state?.field ?? {};

    get field() {
        return this.localField;
    }

    get isDisabled() {
        return this.args.disabled ?? this.args.overlay?.disabled ?? false;
    }

    get meta() {
        const meta = this.field.meta;
        return meta && typeof meta === 'object' ? meta : {};
    }

    get isPassFail() {
        return this.field.type === 'pass-fail';
    }

    get isNumber() {
        return this.field.type === 'number';
    }

    get hasOptions() {
        return isOptionFieldType(this.field.type);
    }

    get options() {
        return Array.isArray(this.field.options) ? this.field.options : [];
    }

    get isOdometer() {
        return this.meta.role === 'odometer';
    }

    /** Every change to the field goes through here, and only from an action. */
    change(attributes) {
        const next = { ...this.field, ...attributes };
        this.localField = next;

        if (this.args.overlay?.state) {
            this.args.overlay.state.field = next;
        }

        if (typeof this.args.onChange === 'function') {
            this.args.onChange(next);
        }
    }

    changeMeta(attributes) {
        this.change({ meta: { ...this.meta, ...attributes } });
    }

    /**
     * The name a label derives to. The label names the field; this machine
     * name follows it until the author types one of their own.
     *
     * Not `dasherize`: it only rewrites spaces and underscores, so a label
     * like "Sidewall condition, offside rear" kept its comma and produced
     * `sidewall-condition,-offside-rear`. The name is an identifier — it
     * travels as an item result's `item_key` and is what a report groups on —
     * so anything that is not a letter or a digit becomes a separator, and
     * runs of separators collapse.
     */
    slugify(value) {
        return String(value ?? '')
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    @action setLabel(event) {
        const label = event.target.value;
        const derived = this.slugify(this.field.label);
        const current = (this.field.name ?? '').trim();

        // The name follows the label until an author types their own.
        const follows = current === '' || current === derived;

        this.change({
            label,
            name: follows ? this.slugify(label) : current,
        });
    }

    @action setName(event) {
        this.change({ name: this.slugify(event.target.value) });
    }

    @action setDescription(event) {
        this.change({ description: event.target.value });
    }

    @action setHelpText(event) {
        this.change({ help_text: event.target.value });
    }

    @action setType(event) {
        const type = event.target.value;
        const attributes = { type, component: componentForFieldType(type) };

        // A field that has just become pass-fail needs the defaults its rules
        // are read from; one that has stopped being pass-fail keeps its meta,
        // because the author may be switching back.
        if (type === 'pass-fail' && this.meta.severity === undefined) {
            attributes.meta = { ...this.meta, severity: 'medium', require_photo_on_fail: false, require_comment_on_fail: false, unsafe_on_fail: false };
        }

        this.change(attributes);
    }

    @action setRequired(required) {
        this.change({ required: Boolean(required) });
    }

    @action setEditable(editable) {
        this.change({ editable: Boolean(editable) });
    }

    @action setColSpan(colSpan) {
        this.changeMeta({ colSpan });
    }

    @action setUnit(event) {
        this.changeMeta({ unit: event.target.value });
    }

    @action toggleOdometerRole(isOdometer) {
        this.changeMeta({ role: isOdometer ? 'odometer' : null });
    }

    @action setSeverity(severity) {
        this.changeMeta({ severity });
    }

    @action setRequirePhotoOnFail(value) {
        this.changeMeta({ require_photo_on_fail: Boolean(value) });
    }

    @action setRequireCommentOnFail(value) {
        this.changeMeta({ require_comment_on_fail: Boolean(value) });
    }

    @action setUnsafeOnFail(value) {
        this.changeMeta({ unsafe_on_fail: Boolean(value) });
    }

    @action setInstructions(event) {
        this.changeMeta({ instructions: event.target.value });
    }

    @action setNewOption(event) {
        this.newOption = event.target.value;
    }

    @action addOption() {
        const option = this.newOption.trim();
        if (option === '') {
            return;
        }

        this.newOption = '';
        this.change({ options: [...this.options, option] });
    }

    @action updateOption(index, event) {
        const value = event.target.value;
        this.change({ options: this.options.map((option, optionIndex) => (optionIndex === index ? value : option)) });
    }

    @action removeOption(index) {
        this.change({ options: this.options.filter((_, optionIndex) => optionIndex !== index) });
    }
}
