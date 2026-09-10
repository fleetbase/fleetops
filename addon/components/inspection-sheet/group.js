import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { summarize, fieldMarker } from '../../utils/inspection-answers';

const MAX_COLUMNS = 4;

/**
 * One group of an inspection form.
 *
 * The author's `grid_size` is honoured for fields that stay compact. A field
 * that needs room keeps its place in the order and spans the full width of
 * the grid instead, which is what keeps one answer from changing the shape of
 * another: alone on its row, it has no neighbouring cell to stretch.
 *
 * It spans in place rather than moving to the end of the group. Moving it
 * re-sorted the group the moment a check failed, and the fields after it
 * jumped up past it.
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
     * The one thing the header says on the right, and only when there is
     * something to say. How the group is laid out is not news to whoever is
     * filling it in.
     */
    get outstanding() {
        return this.intl.t('inspection.record.section-outstanding', { count: this.summary.outstanding });
    }

    get hasOutstanding() {
        return this.summary.outstanding > 0;
    }
}
