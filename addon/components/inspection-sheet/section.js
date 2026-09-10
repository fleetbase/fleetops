import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { summarize } from '../../utils/inspection-answers';

/**
 * One group of an inspection, as a section of the sheet.
 *
 * The header carries the group's name and, on the right, the one number that
 * matters while the sheet is being filled in: what has failed, or what is
 * still owed. It is never a progress bar over answers that were pre-filled —
 * a pass-fail row opens on Pass, so counting it as "answered" would report
 * progress nobody made.
 */
export default class InspectionSheetSectionComponent extends Component {
    @service intl;

    get group() {
        return this.args.group ?? {};
    }

    get title() {
        return this.group.name || this.intl.t('inspection.builder.untitled-group');
    }

    get fields() {
        return Array.isArray(this.group.fields) ? this.group.fields : [];
    }

    get summary() {
        return summarize(this.fields, this.args.values ?? {});
    }

    /** Nothing failed and nothing is owed. */
    get isComplete() {
        return this.fields.length > 0 && this.summary.failed === 0 && this.summary.outstanding === 0;
    }

    get statusText() {
        const { failed, outstanding } = this.summary;

        if (failed) {
            return this.intl.t('inspection.record.section-failed', { count: failed });
        }

        if (outstanding) {
            return this.intl.t('inspection.record.section-outstanding', { count: outstanding });
        }

        if (!this.fields.length) {
            return null;
        }

        return this.intl.t('inspection.record.section-clear');
    }
}
