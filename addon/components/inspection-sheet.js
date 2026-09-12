import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { flattenFields } from '../utils/inspection-form-structure';
import { summarize, listDefects } from '../utils/inspection-answers';
import { INSPECTION_SEVERITIES } from '../utils/inspection-field-types';

/**
 * An inspection form, being filled in.
 *
 * One component renders the sheet wherever it is answered — the console's
 * submission screen, the read-only record, and the public link a driver opens
 * on a phone — so the three cannot drift apart. `@values` in, `@onChange`
 * out; the screen that owns the answers holds them, and nothing is written
 * here during render.
 *
 * The foot is a tally and, when something is wrong, a banner that names the
 * field rather than counting it: an inspector is told what to go and fix, and
 * can jump straight to it.
 */
export default class InspectionSheetComponent extends Component {
    @service intl;

    /** The field a banner last jumped to, held for a moment so the eye finds it. */
    @tracked targetId = null;

    /** The one field whose defect flyout is open. Opening another closes it. */
    @tracked openFieldId = null;

    get groups() {
        return Array.isArray(this.args.groups) ? this.args.groups : [];
    }

    get fields() {
        return flattenFields(this.groups);
    }

    get summary() {
        return summarize(this.fields, this.args.values ?? {});
    }

    get hasFields() {
        return this.fields.length > 0;
    }

    /**
     * Every failure on the sheet, for the tray at its foot: the severity, the
     * field, and what evidence it has or still owes. It is the record of a
     * defect once its flyout is closed, and the review step before submitting.
     */
    get defects() {
        return listDefects(this.fields, this.args.values ?? {}).map((defect) => ({
            ...defect,
            label: defect.field.label || this.intl.t('inspection.builder.untitled-field'),
            severityLabel: this.severityLabel(defect.severity),
            evidence: this.evidenceOf(defect),
        }));
    }

    severityLabel(severity) {
        if (!severity) {
            return this.intl.t('inspection.answer.fail');
        }

        return INSPECTION_SEVERITIES.includes(severity) ? this.intl.t(`inspection.severity.${severity}`) : severity;
    }

    /** "2 photos · comment", or what is still owed, in the order it is owed. */
    evidenceOf(defect) {
        if (defect.needsComment && defect.needsPhoto) {
            return this.intl.t('inspection.defect.needs-both');
        }

        if (defect.needsComment) {
            return this.intl.t('inspection.defect.needs-comment');
        }

        if (defect.needsPhoto) {
            return this.intl.t('inspection.defect.needs-photo');
        }

        const parts = [];

        if (defect.photoCount) {
            parts.push(this.intl.t('inspection.defect.photos', { count: defect.photoCount }));
        }

        if (defect.hasComment) {
            parts.push(this.intl.t('inspection.defect.comment'));
        }

        return parts.length ? parts.join(' · ') : this.intl.t('inspection.defect.no-evidence');
    }

    get outstandingDescription() {
        const field = this.summary.firstOutstanding;

        if (!field) {
            return null;
        }

        return this.intl.t('inspection.record.outstanding-field', {
            label: field.label || this.intl.t('inspection.builder.untitled-field'),
        });
    }

    /**
     * Scroll a named field into view and mark it.
     *
     * By id rather than by a held element reference: a promoted field moves
     * between the grid and its band as the answer changes, so the element the
     * banner points at is not the one that existed when the banner rendered.
     */
    @action openFlyout(field) {
        this.openFieldId = field?.uuid ?? null;
    }

    /**
     * Close a field's flyout — only if it is still the open one, so a close
     * that arrives after another field has opened cannot shut the new one.
     */
    @action closeFlyout(field) {
        if (!field || this.openFieldId === field.uuid) {
            this.openFieldId = null;
        }
    }

    /** From the tray: bring the defect into view and open it to be edited. */
    @action reviewDefect(field) {
        this.jumpTo(field);

        if (!this.args.readonly && !this.args.disabled) {
            this.openFieldId = field?.uuid ?? null;
        }
    }

    @action jumpTo(field) {
        if (!field?.uuid) {
            return;
        }

        this.targetId = field.uuid;

        const element = document.getElementById(`inspection-field-${field.uuid}`);

        if (element) {
            element.scrollIntoView({ behavior: 'smooth', block: 'center' });
            element.querySelector('input, textarea, button')?.focus({ preventScroll: true });
        }
    }
}
