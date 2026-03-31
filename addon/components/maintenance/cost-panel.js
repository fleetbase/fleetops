import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import { isArray } from '@ember/array';

/**
 * `Maintenance::CostPanel`
 *
 * Renders an invoice-style line items editor for a maintenance record.
 *
 * Line items are stored in the `line_items` JSON column on the maintenance
 * record. Each item has: { description, quantity, unit_cost, currency }.
 *
 * Parts cost and total cost are recomputed server-side after every mutation;
 * the component reflects the updated values returned in the API response.
 *
 * Usage:
 *   <Maintenance::CostPanel @resource={{this.maintenance}} @disabled={{cannot-write this.maintenance}} />
 */
export default class MaintenanceCostPanelComponent extends Component {
    @service fetch;
    @service notifications;
    @service intl;

    /** Whether the "Add line item" row is currently visible */
    @tracked isAddingItem = false;

    /** Tracks which line item index is currently being edited (null = none) */
    @tracked editingIndex = null;

    /** Draft values for the add/edit form row */
    @tracked draftDescription = '';
    @tracked draftQuantity = 1;
    @tracked draftUnitCost = 0;

    // ─── Computed helpers ────────────────────────────────────────────────────

    get lineItems() {
        return isArray(this.args.resource?.line_items) ? this.args.resource.line_items : [];
    }

    get currency() {
        return this.args.resource?.currency ?? 'USD';
    }

    get laborCost() {
        return this.args.resource?.labor_cost ?? 0;
    }

    get tax() {
        return this.args.resource?.tax ?? 0;
    }

    get partsCost() {
        return this.lineItems.reduce((sum, item) => sum + (item.quantity ?? 0) * (item.unit_cost ?? 0), 0);
    }

    get totalCost() {
        return this.laborCost + this.partsCost + this.tax;
    }

    get draftLineTotal() {
        return (this.draftQuantity ?? 0) * (this.draftUnitCost ?? 0);
    }

    get isDisabled() {
        return this.args.disabled ?? false;
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

    @task({ drop: true })
    *addLineItem() {
        if (!this.draftDescription?.trim()) {
            this.notifications.warning('Please enter a description for the line item.');
            return;
        }

        const resource = this.args.resource;
        const payload = {
            description: this.draftDescription,
            quantity: this.draftQuantity,
            unit_cost: this.draftUnitCost,
            currency: this.currency,
        };

        try {
            const response = yield this.fetch.post(`fleet-ops/maintenances/${resource.id}/line-items`, payload);
            this._applyResponse(resource, response);
            this.isAddingItem = false;
            this._resetDraft();
        } catch (error) {
            this.notifications.serverError(error);
        }
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
        this.draftUnitCost = item.unit_cost ?? 0;
    }

    @action
    cancelEdit() {
        this.editingIndex = null;
        this._resetDraft();
    }

    @task({ drop: true })
    *saveEdit() {
        if (!this.draftDescription?.trim()) {
            this.notifications.warning('Please enter a description for the line item.');
            return;
        }

        const resource = this.args.resource;
        const index = this.editingIndex;
        const payload = {
            description: this.draftDescription,
            quantity: this.draftQuantity,
            unit_cost: this.draftUnitCost,
            currency: this.currency,
        };

        try {
            const response = yield this.fetch.put(`fleet-ops/maintenances/${resource.id}/line-items/${index}`, payload);
            this._applyResponse(resource, response);
            this.editingIndex = null;
            this._resetDraft();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    // ─── Remove item ─────────────────────────────────────────────────────────

    @task({ drop: true })
    *removeLineItem(index) {
        const resource = this.args.resource;

        try {
            const response = yield this.fetch.delete(`fleet-ops/maintenances/${resource.id}/line-items/${index}`);
            this._applyResponse(resource, response);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    /**
     * Returns the line total for a single item (quantity × unit_cost).
     * Used in the template as (this.lineTotal item).
     */
    @action
    lineTotal(item) {
        return (item?.quantity ?? 0) * (item?.unit_cost ?? 0);
    }

    _resetDraft() {
        this.draftDescription = '';
        this.draftQuantity = 1;
        this.draftUnitCost = 0;
    }

    /**
     * Apply the server response back onto the Ember Data record so the UI
     * reflects the recomputed parts_cost and total_cost without a full reload.
     */
    _applyResponse(resource, response) {
        if (response?.line_items !== undefined) {
            resource.set('line_items', response.line_items);
        }
        if (response?.total_cost !== undefined) {
            resource.set('total_cost', response.total_cost);
        }
        // Recompute parts_cost locally from the updated line items
        const items = isArray(response?.line_items) ? response.line_items : [];
        const partsCost = items.reduce((sum, item) => sum + (item.quantity ?? 0) * (item.unit_cost ?? 0), 0);
        resource.set('parts_cost', partsCost);
    }
}
