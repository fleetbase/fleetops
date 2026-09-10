import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { dasherize } from '@ember/string';
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

    get field() {
        return this.args.field ?? {};
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
        if (typeof this.args.onChange === 'function') {
            this.args.onChange({ ...this.field, ...attributes });
        }
    }

    changeMeta(attributes) {
        this.change({ meta: { ...this.meta, ...attributes } });
    }

    /**
     * The label names the field; the machine name follows it until the author
     * types one of their own, matching the platform's editor.
     */
    @action setLabel(event) {
        const label = event.target.value;
        const derived = dasherize((this.field.label ?? '').trim().toLowerCase());
        const current = (this.field.name ?? '').trim();
        const follows = current === '' || current === derived;

        this.change({
            label,
            name: follows ? dasherize(label.trim().toLowerCase()) : current,
        });
    }

    @action setName(event) {
        this.change({ name: dasherize(event.target.value.trim().toLowerCase()) });
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
