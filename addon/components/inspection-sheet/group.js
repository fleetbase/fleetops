import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { summarize, isPromoted, fieldMarker } from '../../utils/inspection-answers';

const MAX_COLUMNS = 4;

/**
 * One group of an inspection form.
 *
 * The author's `grid_size` is honoured — but only for the fields that stay
 * compact. A field that needs room is promoted out of the grid into a
 * full-width band underneath it, which is what keeps one answer from changing
 * the shape of another: after promotion there is no neighbouring cell left to
 * stretch.
 *
 * The header carries one dot per field, in the order they are answered, so an
 * inspector can see what is still open in a group without reading a label.
 */
export default class InspectionSheetGroupComponent extends Component {
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

    get values() {
        return this.args.values ?? {};
    }

    /** What the author asked for, within what a panel can actually show. */
    get columns() {
        const size = Number(this.group.meta?.grid_size);

        if (!Number.isFinite(size) || size < 1) {
            return 1;
        }

        return Math.min(Math.round(size), MAX_COLUMNS);
    }

    get compactFields() {
        return this.fields.filter((field) => !isPromoted(field, this.values[field.uuid]));
    }

    /** Promoted fields keep the order they were authored in. */
    get promotedFields() {
        return this.fields.filter((field) => isPromoted(field, this.values[field.uuid]));
    }

    get markers() {
        return this.fields.map((field) => ({
            uuid: field.uuid,
            marker: fieldMarker(field, this.values[field.uuid]),
        }));
    }

    get summary() {
        return summarize(this.fields, this.values);
    }

    /**
     * The line at the right of the header. What is outstanding takes
     * precedence over how the group is laid out — an inspector needs the first
     * far more often than the second.
     */
    get meta() {
        const outstanding = this.summary.outstanding;

        if (outstanding) {
            return this.intl.t('inspection.record.section-outstanding', { count: outstanding });
        }

        const promoted = this.promotedFields.length;

        if (promoted) {
            return this.intl.t('inspection.record.columns-and-promoted', { columns: this.columns, promoted });
        }

        return this.intl.t('inspection.record.columns', { columns: this.columns });
    }

    get hasOutstanding() {
        return this.summary.outstanding > 0;
    }
}
