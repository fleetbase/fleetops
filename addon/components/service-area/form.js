import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { usStates } from '../../utils/fleet-ops-options';

/**
 * ServiceArea form backing class (Phase 2 Task 14 extension).
 *
 * Adds geographic-scoping bindings on top of the existing form:
 *   - `states_list`: array of 2-letter state codes, edited via
 *     PowerSelectMultiple. Bound through `selectedStates` getter (codes
 *     -> option objects) and `onStatesChange` (option objects -> codes).
 *   - `zip_ranges`: array of `{start, end}` objects, edited via the
 *     `<ServiceArea::ZipRangesEditor />` component. Editor display state
 *     lives in `zipRangesDisplay` (may include transient empty rows for
 *     in-progress entry); on every edit we mirror the *cleaned* version
 *     into `resource.zip_ranges` so the persisted model never contains
 *     `{start: "", end: ""}` placeholders.
 *
 * Polygon (`border`), color, country, and base details bindings are
 * untouched — additive change per Task 9 design boundary.
 */
export default class ServiceAreaFormComponent extends Component {
    usStates = usStates;

    /**
     * Editor display state. Seeded from the resource on construction; the
     * editor mutates this directly (via @value/@onChange), and we mirror
     * a cleaned snapshot to `resource.zip_ranges` for persistence.
     */
    @tracked zipRangesDisplay = [];

    constructor() {
        super(...arguments);
        const initial = this.args.resource?.zip_ranges;
        this.zipRangesDisplay = Array.isArray(initial) ? [...initial] : [];
    }

    /**
     * Map the resource's stored state codes to PowerSelect option objects.
     * Used to drive PowerSelectMultiple's `@selected`.
     */
    get selectedStates() {
        const codes = this.args.resource?.states_list ?? [];
        if (!Array.isArray(codes) || codes.length === 0) {
            return [];
        }
        return usStates.filter((opt) => codes.includes(opt.value));
    }

    /**
     * Convert PowerSelectMultiple's selected option objects back to the
     * locked string-array shape and assign to the model.
     */
    @action
    onStatesChange(selectedOpts) {
        const codes = (selectedOpts ?? []).map((o) => o.value);
        this.args.resource.states_list = codes;
    }

    /**
     * Editor change handler. Updates the display state (which may contain
     * empty rows the user is still typing into) and mirrors a cleaned
     * snapshot to the resource so the persisted JSON is well-formed.
     */
    @action
    onZipRangesChange(ranges) {
        const next = Array.isArray(ranges) ? ranges : [];
        this.zipRangesDisplay = next;
        this.args.resource.zip_ranges = this._cleanRanges(next);
    }

    /**
     * Strip fully-empty rows. Partial rows (start filled, end blank or
     * vice versa) are kept — backend validation is deferred per Task 9.
     */
    _cleanRanges(ranges) {
        return ranges.filter((r) => {
            const start = (r?.start ?? '').toString().trim();
            const end = (r?.end ?? '').toString().trim();
            return start !== '' || end !== '';
        });
    }
}
