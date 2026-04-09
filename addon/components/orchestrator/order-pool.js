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

/**
 * Default standard fields shown when no card-field settings have been saved.
 * Each entry includes icon and highlight metadata so the HBS can render them
 * with the same visual treatment as the original hardcoded block.
 */
const DEFAULT_FIELDS = [
    { key: 'pickup',           icon: 'location-crosshairs', iconClass: 'text-green-400' },
    { key: 'dropoff',          icon: 'location-dot',        iconClass: 'text-red-400' },
    { key: 'scheduled_at',     icon: 'clock',               highlight: 'text-blue-500 dark:text-blue-400' },
    { key: 'customer',         icon: 'user' },
    { key: 'driver_assigned',  icon: 'id-badge' },
    { key: 'vehicle_assigned', icon: 'truck' },
    { key: 'created_at',       icon: 'calendar-plus',       highlight: 'text-gray-500 dark:text-gray-400' },
];

/** Icon/highlight metadata for known standard field keys. */
const FIELD_META = {
    tracking:          { icon: 'hashtag' },
    status:            { icon: 'circle-dot' },
    scheduled_at:      { icon: 'clock',               highlight: 'text-blue-500 dark:text-blue-400' },
    customer:          { icon: 'user' },
    type:              { icon: 'tag' },
    notes:             { icon: 'note-sticky' },
    priority:          { icon: 'flag' },
    dropoff:           { icon: 'location-dot',         iconClass: 'text-red-400' },
    pickup:            { icon: 'location-crosshairs',  iconClass: 'text-green-400' },
    driver_assigned:   { icon: 'id-badge' },
    vehicle_assigned:  { icon: 'truck' },
    created_at:        { icon: 'calendar-plus',        highlight: 'text-gray-500 dark:text-gray-400' },
};

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
     * invoked with arguments from HBS:
     *   {{#each (this.resolvedCardFields order) as |field|}}
     *
     * Returns an array of { label, value, icon?, iconClass?, highlight? }.
     *
     * When no cardFields settings have been saved (@arg cardFields is null/undefined)
     * it falls back to DEFAULT_FIELDS so the card always shows a useful set of
     * information without requiring the dispatcher to configure anything first.
     */
    @action resolvedCardFields(order) {
        const cardFields = this.args.cardFields;

        // ── Default fallback (no settings saved yet) ──────────────────────────
        if (!cardFields) {
            return DEFAULT_FIELDS
                .map(({ key, icon, iconClass, highlight }) => {
                    const { value, label } = this._resolveStandardFieldFull(order, key);
                    if (!value) return null;
                    return { label, value, icon, iconClass, highlight };
                })
                .filter(Boolean);
        }

        const fields = [];

        // ── Standard fields (from settings) ───────────────────────────────────
        for (const key of cardFields.standard ?? []) {
            const { value, label } = this._resolveStandardFieldFull(order, key);
            if (!value) continue;
            const meta = FIELD_META[key] ?? {};
            fields.push({ label, value, ...meta });
        }

        // ── Config-specific custom fields ──────────────────────────────────────
        // Custom fields are flattened onto the order payload by withCustomFields() using
        // Str::snake(Str::lower(label)) as the key. We try three lookup strategies:
        //   1. Direct property access by fieldKey (matches when name === snake(label))
        //   2. Scan the custom_field_values collection (included on internal requests)
        //      and match on customField.name or customField.label
        //   3. Fall back to scanning all top-level order keys for a close match
        const configUuid = order.order_config_uuid;
        if (configUuid && cardFields.byConfig?.[configUuid]) {
            for (const fieldKey of cardFields.byConfig[configUuid]) {
                let label = fieldKey;
                let value = null;

                // Strategy 1: flat property on order (most common path)
                const directVal = order[fieldKey];
                if (directVal !== undefined && directVal !== null && typeof directVal !== 'object') {
                    value = String(directVal);
                }

                // Strategy 2: scan custom_field_values collection
                if (value === null) {
                    const cfvCollection = order.custom_field_values ?? order.customFieldValues ?? [];
                    const cfvArray = typeof cfvCollection.toArray === 'function' ? cfvCollection.toArray() : (Array.isArray(cfvCollection) ? cfvCollection : []);
                    const cfv = cfvArray.find((v) => {
                        const cfName = v.custom_field?.name ?? v.customField?.get?.('name') ?? v.field_key ?? '';
                        const cfLabel = v.custom_field?.label ?? v.customField?.get?.('label') ?? v.label ?? '';
                        return cfName === fieldKey || cfLabel === fieldKey;
                    });
                    if (cfv) {
                        const rawVal = cfv.value ?? cfv.string_value ?? cfv.raw_value;
                        label = cfv.custom_field?.label ?? cfv.customField?.get?.('label') ?? fieldKey;
                        value = (rawVal !== null && rawVal !== undefined && typeof rawVal !== 'object')
                            ? String(rawVal)
                            : (typeof rawVal === 'object' && rawVal !== null ? JSON.stringify(rawVal) : null);
                    }
                }

                if (value !== null) {
                    fields.push({ label, value });
                }
            }
        }

        // ── Meta fields ────────────────────────────────────────────────────────
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

    /**
     * Returns { label, value } for a standard field key.
     * Labels are human-readable strings (not raw keys).
     */
    _resolveStandardFieldFull(order, key) {
        const t = (k) => {
            try { return this.intl.t(`orchestrator.${k}`); } catch { return k; }
        };
        const map = {
            tracking:          { label: t('field-tracking'),          value: order.tracking ?? order.public_id },
            status:            { label: t('field-status'),            value: order.status },
            scheduled_at:      { label: t('scheduled'),               value: order.scheduled_at ? this._formatDate(order.scheduled_at) : null },
            customer:          { label: t('customer'),                value: order.customer?.name },
            type:              { label: t('field-type'),              value: order.type },
            notes:             { label: t('field-notes'),             value: order.notes },
            priority:          { label: t('field-priority'),          value: order.orchestrator_priority != null ? String(order.orchestrator_priority) : null },
            dropoff:           { label: t('dropoff'),                 value: order.payload?.dropoff?.address ?? order.dropoff_name },
            pickup:            { label: t('pickup'),                  value: order.payload?.pickup?.address ?? order.pickup_name },
            driver_assigned:   { label: t('driver-assigned'),        value: order.driver_assigned?.name },
            vehicle_assigned:  { label: t('vehicle-assigned'),       value: order.vehicle_assigned?.display_name ?? order.vehicle_assigned?.name },
            created_at:        { label: t('created'),                 value: order.created_at ? this._formatDate(order.created_at) : null },
        };
        return map[key] ?? { label: key, value: order[key] != null ? String(order[key]) : null };
    }

    _formatDate(date) {
        if (!date) return null;
        try {
            return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(date));
        } catch {
            return String(date);
        }
    }
}
