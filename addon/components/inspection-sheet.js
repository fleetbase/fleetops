import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { flattenFields } from '../utils/inspection-form-structure';
import { summarize, passFailAnswer } from '../utils/inspection-answers';

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

    /** "Lights and indicators · High" — the failure, and how bad it is. */
    get unsafeDescription() {
        const field = this.summary.unsafeField;

        if (!field) {
            return null;
        }

        const severity = passFailAnswer(this.args.values?.[field.uuid])?.severity;
        const label = field.label || this.intl.t('inspection.builder.untitled-field');

        if (!severity) {
            return label;
        }

        return `${label} · ${this.intl.t(`inspection.severity.${severity}`)}`;
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
