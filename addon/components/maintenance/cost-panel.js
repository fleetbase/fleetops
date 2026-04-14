import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import numbersOnly from '@fleetbase/ember-core/utils/numbers-only';

/**
 * `Maintenance::CostPanel`
 *
 * Renders an invoice-style line items editor for a maintenance record.
 *
 * Line items are stored in the `line_items` JSON column on the maintenance
 * record (`@attr('raw')`). Mutations are purely in-memory — the array is
 * written back onto `@resource.line_items` and the parent form's normal
 * save flow persists everything together in a single request. This means
 * the component works correctly for both new (unsaved) and existing records.
 *
 * All monetary values (unit_cost, labor_cost, tax, parts_cost, total_cost)
 * are stored as **cents** (integers) on the backend via the Money::class cast
 * and surfaced as strings by `@attr('string')` on the Ember model. The
 * `numbersOnly()` utility strips any non-digit characters and `parseInt`
 * converts to a safe integer before arithmetic.
 *
 * The `MoneyInput` component handles the cents ↔ display conversion:
 *   - `@value` receives cents (e.g. 1000 = $10.00)
 *   - `@onChange` emits cents (e.g. 1000 = $10.00)
 *
 * Usage:
 *   <Maintenance::CostPanel @resource={{this.maintenance}} @disabled={{cannot-write this.maintenance}} />
 */
export default class MaintenanceCostPanelComponent extends Component {
    @service notifications;
    @service intl;

    /** Whether the "Add line item" row is currently visible */
    @tracked isAddingItem = false;

    /** Tracks which line item index is currently being edited (null = none) */
    @tracked editingIndex = null;

    /**
     * Draft values for the add/edit form row.
     * `draftUnitCost` is stored in **cents** (integer) to match the backend
     * Money::class cast and the MoneyInput component's expected input/output format.
     */
    @tracked draftDescription = '';
    @tracked draftQuantity = 1;
    @tracked draftUnitCost = 0;

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Safely parse a monetary string attribute (cents stored as string) to int.
     */
    @action _toCents(value) {
        if (value === null || value === undefined || value === '') return 0;
        const parsed = parseInt(numbersOnly(String(value)), 10);
        return isNaN(parsed) ? 0 : parsed;
    }

    // ─── Computed getters ─────────────────────────────────────────────────────

    get lineItems() {
        return isArray(this.args.resource?.line_items) ? this.args.resource.line_items : [];
    }

    get currency() {
        return this.args.resource?.currency ?? 'USD';
    }

    get laborCost() {
        return this._toCents(this.args.resource?.labor_cost);
    }

    get tax() {
        return this._toCents(this.args.resource?.tax);
    }

    get partsCost() {
        return this.lineItems.reduce((sum, item) => {
            const qty = parseInt(item.quantity, 10) || 0;
            const cost = this._toCents(item.unit_cost);
            return sum + qty * cost;
        }, 0);
    }

    get totalCost() {
        return this.laborCost + this.partsCost + this.tax;
    }

    /**
     * Live preview of the draft line total (in cents).
     * Displayed via `format-currency` which also expects cents.
     */
    get draftLineTotal() {
        return (parseInt(this.draftQuantity, 10) || 0) * this._toCents(this.draftUnitCost);
    }

    get isDisabled() {
        return this.args.disabled ?? false;
    }

    // ─── Draft unit cost setter ───────────────────────────────────────────────

    /**
     * Called by MoneyInput's `@onChange`. The value is already in cents.
     */
    @action
    setDraftUnitCost(cents) {
        this.draftUnitCost = cents ?? 0;
    }

    // ─── Line item helpers ────────────────────────────────────────────────────

    /**
     * Returns the line total for a single item (quantity × unit_cost).
     * Both values are in cents, so the result is also in cents.
     * Used in the template as (this.lineTotal item).
     */
    @action
    lineTotal(item) {
        const qty = parseInt(item?.quantity, 10) || 0;
        const cost = this._toCents(item?.unit_cost);
        return qty * cost;
    }

    /**
     * Write a new copy of the line items array back onto the resource so
     * Ember Data tracks the change and includes it in the next save().
     */
    _commitItems(items) {
        this.args.resource.set('line_items', [...items]);
        // Recompute parts_cost and total_cost locally so the summary updates
        // immediately without waiting for a server round-trip.
        const partsCost = items.reduce((sum, item) => {
            return sum + (parseInt(item.quantity, 10) || 0) * this._toCents(item.unit_cost);
        }, 0);
        this.args.resource.set('parts_cost', String(partsCost));
        this.args.resource.set('total_cost', String(this.laborCost + partsCost + this.tax));
    }

    // ─── Add item ────────────────────────────────────────────────────────────

    @action
    showAddRow() {
        this.isAddingItem = true;
        this.editingIndex = null;
        this._resetDraft();
    }

    @action
    cancelAdd() {
        this.isAddingItem = false;
        this._resetDraft();
    }

    @action
    addLineItem() {
        if (!this.draftDescription?.trim()) {
            this.notifications.warning('Please enter a description for the line item.');
            return;
        }

        const newItem = {
            description: this.draftDescription.trim(),
            quantity: parseInt(this.draftQuantity, 10) || 1,
            unit_cost: this.draftUnitCost,
            currency: this.currency,
        };

        this._commitItems([...this.lineItems, newItem]);
        this.isAddingItem = false;
        this._resetDraft();
    }

    // ─── Edit item ───────────────────────────────────────────────────────────

    @action
    startEdit(index) {
        const item = this.lineItems[index];
        if (!item) return;
        this.editingIndex = index;
        this.isAddingItem = false;
        this.draftDescription = item.description ?? '';
        this.draftQuantity = item.quantity ?? 1;
        // unit_cost from the record is already in cents
        this.draftUnitCost = this._toCents(item.unit_cost);
    }

    @action
    cancelEdit() {
        this.editingIndex = null;
        this._resetDraft();
    }

    @action
    saveEdit() {
        if (!this.draftDescription?.trim()) {
            this.notifications.warning('Please enter a description for the line item.');
            return;
        }

        const index = this.editingIndex;
        const updatedItems = this.lineItems.map((item, i) => {
            if (i !== index) return item;
            return {
                ...item,
                description: this.draftDescription.trim(),
                quantity: parseInt(this.draftQuantity, 10) || 1,
                unit_cost: this.draftUnitCost,
                currency: this.currency,
            };
        });

        this._commitItems(updatedItems);
        this.editingIndex = null;
        this._resetDraft();
    }

    // ─── Remove item ─────────────────────────────────────────────────────────

    @action
    removeLineItem(index) {
        const updatedItems = this.lineItems.filter((_, i) => i !== index);
        this._commitItems(updatedItems);
        // If we were editing this item, close the edit row
        if (this.editingIndex === index) {
            this.editingIndex = null;
            this._resetDraft();
        }
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    _resetDraft() {
        this.draftDescription = '';
        this.draftQuantity = 1;
        this.draftUnitCost = 0;
    }
}
