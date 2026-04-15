import Component from '@glimmer/component';
import { action } from '@ember/object';

/**
 * Editor widget for the ServiceArea `zip_ranges` JSON column (Phase 2 Task 14).
 *
 * Locked data shape (from Task 9):
 *   [{ start: "10001", end: "14999" }, { start: "01001", end: "02999" }]
 *
 * Strings (not numbers) preserve leading zeros. Empty array / null = "no
 * zip scoping set." Matching / precedence logic is deferred — this widget
 * only edits the array; backend validation is also deferred.
 *
 * Binding contract:
 *   @value     — current array of {start, end} objects (or null/undefined)
 *   @onChange  — invoked with the next array; parent owns persistence
 *   @resource  — optional, used for cannot-write disabling
 *
 * Empty rows are kept in display until the user fills or removes them
 * (Add ZIP range pushes a transient empty row); the parent form's save
 * action filters them out before persisting (see service-area/form.js).
 */
export default class ServiceAreaZipRangesEditorComponent extends Component {
    /**
     * Read-through getter. Never mutates @value; we always emit a new array
     * via @onChange so the parent stays the single source of truth.
     */
    get ranges() {
        const value = this.args.value;
        return Array.isArray(value) ? value : [];
    }

    @action
    addRange() {
        const next = [...this.ranges, { start: '', end: '' }];
        this.args.onChange?.(next);
    }

    @action
    updateRange(index, field, event) {
        const value = event?.target?.value ?? '';
        const next = this.ranges.map((row, i) => (i === index ? { ...row, [field]: value } : row));
        this.args.onChange?.(next);
    }

    @action
    removeRange(index) {
        const next = this.ranges.filter((_, i) => i !== index);
        this.args.onChange?.(next);
    }
}
