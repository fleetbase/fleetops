import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { INSPECTION_SEVERITIES } from '../../utils/inspection-field-types';
import { answerState, ROOMY_FIELD_TYPES } from '../../utils/inspection-answers';

/**
 * One stored answer, read-only — what the record's Overview shows.
 *
 * The submission resource hands the console a value already projected: a file
 * value resolved to `{ id, url, filename, content_type }`, and a pass-fail
 * answer as an object with its photos resolved the same way. This renders
 * that, whichever shape the value arrived in; it never fetches and never
 * writes.
 */
export default class InspectionFieldValueComponent extends Component {
    @service intl;

    get field() {
        return this.args.field ?? {};
    }

    get meta() {
        const meta = this.field.meta;
        return meta && typeof meta === 'object' ? meta : {};
    }

    get label() {
        return this.field.label || this.field.name || this.intl.t('inspection.builder.untitled-field');
    }

    get answerState() {
        return answerState(this.field, this.args.value);
    }

    get isDefect() {
        return this.isPassFail && this.answerState === 'fail';
    }

    get isRoomy() {
        return ROOMY_FIELD_TYPES.includes(this.field.type);
    }

    get isStackedBand() {
        return this.field.type === 'textarea';
    }

    get isTargeted() {
        return Boolean(this.field.uuid) && this.args.targetId === this.field.uuid;
    }

    /** A failure's comment and photos, shown only when there is something to show. */
    get hasDefectDetail() {
        return this.isPassFail && (Boolean(this.answer.comments) || this.photos.length > 0);
    }

    get isPassFail() {
        return this.field.type === 'pass-fail';
    }

    get isFile() {
        return this.field.type === 'file-upload' || this.field.type === 'signature';
    }

    get isBoolean() {
        return this.field.type === 'boolean';
    }

    get answer() {
        const value = this.args.value;
        if (value && typeof value === 'object' && !Array.isArray(value)) {
            return { ...value, photos: Array.isArray(value.photos) ? value.photos : [] };
        }

        if (typeof value === 'boolean') {
            return { passed: value, not_applicable: false, photos: [] };
        }

        return { passed: null, not_applicable: false, photos: [] };
    }

    get resultLabel() {
        const answer = this.answer;
        if (answer.not_applicable === true) {
            return 'inspection.answer.not-applicable';
        }

        if (answer.passed === false) {
            return 'inspection.answer.fail';
        }

        if (answer.passed === true) {
            return 'inspection.answer.pass';
        }

        return 'inspection.answer.unanswered';
    }

    get resultStatus() {
        const answer = this.answer;
        if (answer.not_applicable === true) {
            return 'info';
        }

        return answer.passed === false ? 'danger' : 'success';
    }

    /** The severity's own label, or the raw value when it is not one of ours. */
    get severityLabel() {
        const severity = this.answer.severity;
        return INSPECTION_SEVERITIES.includes(severity) ? `inspection.severity.${severity}` : null;
    }

    get photos() {
        return this.answer.photos.map((photo) => this.#describeFile(photo));
    }

    get file() {
        return this.#describeFile(this.args.value);
    }

    get booleanValue() {
        const value = this.args.value;
        return value === true || value === 'true' || value === 1 || value === '1';
    }

    #describeFile(value) {
        if (value && typeof value === 'object') {
            return { reference: value.id, url: value.url, filename: value.filename };
        }

        if (typeof value !== 'string' || value === '') {
            return { reference: null, url: null, filename: null };
        }

        return { reference: value, url: value.startsWith('http') ? value : null, filename: null };
    }
}
