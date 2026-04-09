import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

/**
 * Orchestrator::OrderPool
 *
 * Left panel of the Orchestrator Workbench. Displays the filterable,
 * searchable list of orders available for allocation. Handles order
 * selection (checkbox + click) and drag initiation.
 *
 * @arg orders              - Full array of available orders
 * @arg selectedOrderIds    - Set of selected order public_ids
 * @arg isLoading           - Whether data is loading
 * @arg cardFields          - Configured card fields { standard: [], byConfig: {}, meta: [] }
 * @arg onToggleSelection   - Action(order) — toggle order selection
 * @arg onClearSelection    - Action — clear all selections
 * @arg onDragStart         - Action(order, event) — drag start handler
 * @arg onOpenImport        - Action — open import modal
 */
export default class OrchestratorOrderPoolComponent extends Component {
    @service intl;

    @tracked orderSearch = '';
    @tracked orderFilter = 'all';

    @action onSearchInput(event) {
        this.orderSearch = event.target.value;
    }

    @action setFilter(filter) {
        this.orderFilter = filter;
    }

    @action stopPropagation(event) {
        event?.stopPropagation();
    }

    /**
     * resolvedCardFields — decorated as @action so Glimmer allows it to be
     * invoked with arguments from HBS: {{#each (this.resolvedCardFields order) as |field|}}
     */
    @action resolvedCardFields(order) {
        const cardFields = this.args.cardFields;
        if (!cardFields) return [];
        const fields = [];

        // Standard fields
        for (const key of cardFields.standard ?? []) {
            const value = this._resolveStandardField(order, key);
            if (value) fields.push({ label: key, value });
        }

        // Config-specific custom fields
        const configUuid = order.order_config_uuid;
        if (configUuid && cardFields.byConfig?.[configUuid]) {
            for (const fieldKey of cardFields.byConfig[configUuid]) {
                const cfv = order.custom_field_values?.find?.((v) => v.field_key === fieldKey);
                if (cfv) {
                    fields.push({ label: cfv.label ?? fieldKey, value: cfv.value });
                }
            }
        }

        // Meta fields
        for (const key of cardFields.meta ?? []) {
            const val = order.meta?.[key];
            if (val !== undefined && val !== null) {
                fields.push({ label: key, value: String(val) });
            }
        }

        return fields;
    }

    /**
     * priorityBadgeStatus — @action so it can be called with an argument from HBS.
     * Returns a Badge @status string.
     */
    @action priorityBadgeStatus(priority) {
        if (priority >= 75) return 'error';
        if (priority >= 50) return 'warning';
        return 'info';
    }

    get filteredOrders() {
        let orders = this.args.orders ?? [];
        if (this.orderSearch) {
            const q = this.orderSearch.toLowerCase();
            orders = orders.filter(
                (o) =>
                    o.tracking?.toLowerCase().includes(q) ||
                    o.public_id?.toLowerCase().includes(q) ||
                    o.payload?.dropoff?.address?.toLowerCase().includes(q) ||
                    o.payload?.pickup?.address?.toLowerCase().includes(q)
            );
        }
        if (this.orderFilter === 'scheduled') {
            orders = orders.filter((o) => o.scheduled_at);
        } else if (this.orderFilter === 'urgent') {
            orders = orders.filter((o) => (o.orchestrator_priority ?? 0) >= 75);
        } else if (this.orderFilter === 'imported') {
            orders = orders.filter((o) => o.meta?.imported_via_orchestrator);
        } else if (this.orderFilter === 'unplanned') {
            orders = orders.filter((o) => o.status === 'created' && !o.vehicle_assigned_uuid);
        }
        return orders;
    }

    get selectedOrderIdsArray() {
        return [...(this.args.selectedOrderIds ?? new Set())];
    }

    _resolveStandardField(order, key) {
        const map = {
            tracking: order.tracking ?? order.public_id,
            status: order.status,
            scheduled_at: order.scheduledAt,
            customer: order.customer?.name,
            type: order.type,
            notes: order.notes,
            priority: order.orchestrator_priority,
            dropoff: order.payload?.dropoff?.address ?? order.dropoff_name,
            pickup: order.payload?.pickup?.address ?? order.pickup_name,
        };
        return map[key] ?? order[key] ?? '';
    }
}
