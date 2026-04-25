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
 */
const DEFAULT_FIELDS = [
    { key: 'pickup', icon: 'location-crosshairs', iconClass: 'text-green-400' },
    { key: 'dropoff', icon: 'location-dot', iconClass: 'text-red-400' },
    { key: 'scheduled_at', icon: 'clock', highlight: 'text-blue-500 dark:text-blue-400' },
    { key: 'customer', icon: 'user' },
    { key: 'driver_assigned', icon: 'id-badge' },
    { key: 'vehicle_assigned', icon: 'truck' },
    { key: 'created_at', icon: 'calendar-plus', highlight: 'text-gray-500 dark:text-gray-400' },
];

/** Icon/highlight metadata for known standard field keys. */
const FIELD_META = {
    tracking: { icon: 'hashtag' },
    status: { icon: 'circle-dot' },
    scheduled_at: { icon: 'clock', highlight: 'text-blue-500 dark:text-blue-400' },
    customer: { icon: 'user' },
    type: { icon: 'tag' },
    notes: { icon: 'note-sticky' },
    priority: { icon: 'flag' },
    dropoff: { icon: 'location-dot', iconClass: 'text-red-400' },
    pickup: { icon: 'location-crosshairs', iconClass: 'text-green-400' },
    driver_assigned: { icon: 'id-badge' },
    vehicle_assigned: { icon: 'truck' },
    created_at: { icon: 'calendar-plus', highlight: 'text-gray-500 dark:text-gray-400' },
};

export default class OrchestratorOrderPoolComponent extends Component {
    @service intl;

    // ── Quick filter chip ─────────────────────────────────────────────────
    @tracked orderSearch = '';
    @tracked orderFilter = 'all';

    // ── Card body collapse state (hides entire card body) ─────────────────
    @tracked collapsedOrderIds = new Set();
    // ── Inline route collapse state (hides route stop list inside card) ───
    @tracked collapsedRouteIds = new Set();

    // ── Advanced filter panel ─────────────────────────────────────────────────
    @tracked showAdvanced = false;
    @tracked advancedCountry = null;
    @tracked advancedType = null;
    @tracked advancedStatus = null;
    @tracked advancedDate = null;

    // ── Quick filter ──────────────────────────────────────────────────────────

    @action onSearchInput(event) {
        this.orderSearch = event.target.value;
    }

    @action setFilter(filter) {
        this.orderFilter = filter;
    }

    @action stopPropagation(event) {
        event?.stopPropagation();
    }

    // ── Waypoint collapse actions ─────────────────────────────────────────

    @action toggleWaypointCollapse(orderId, event) {
        event?.stopPropagation();
        const next = new Set(this.collapsedOrderIds);
        if (next.has(orderId)) {
            next.delete(orderId);
        } else {
            next.add(orderId);
        }
        this.collapsedOrderIds = next;
    }

    get allWaypointsCollapsed() {
        const orders = this.filteredOrders ?? [];
        return orders.length > 0 && orders.every((o) => this.collapsedOrderIds.has(o.public_id));
    }

    @action toggleAllWaypoints() {
        if (this.allWaypointsCollapsed) {
            this.collapsedOrderIds = new Set();
        } else {
            const ids = (this.filteredOrders ?? []).map((o) => o.public_id).filter(Boolean);
            this.collapsedOrderIds = new Set(ids);
        }
    }

    @action collapseAllWaypoints() {
        const ids = (this.filteredOrders ?? []).map((o) => o.public_id).filter(Boolean);
        this.collapsedOrderIds = new Set(ids);
    }

    @action expandAllWaypoints() {
        this.collapsedOrderIds = new Set();
    }

    @action isWaypointCollapsed(orderId) {
        return this.collapsedOrderIds.has(orderId);
    }

    // ── Inline route collapse actions ────────────────────────────────────

    @action toggleRouteCollapse(orderId, event) {
        event?.stopPropagation();
        const next = new Set(this.collapsedRouteIds);
        if (next.has(orderId)) {
            next.delete(orderId);
        } else {
            next.add(orderId);
        }
        this.collapsedRouteIds = next;
    }

    @action isRouteCollapsed(orderId) {
        return this.collapsedRouteIds.has(orderId);
    }

    /**
     * orderRouteStops — returns an array of { label, address } objects
     * representing the ordered route stops for an order, using A/B/C labels.
     */
    @action orderRouteStops(order) {
        const stops = [];
        const payload = order.payload;

        if (!payload) {
            if (order.pickup_name) stops.push(order.pickup_name);
            if (order.dropoff_name) stops.push(order.dropoff_name);
            return stops.map((address, i) => ({ label: String.fromCharCode(65 + i), address }));
        }

        if (payload.pickup) {
            stops.push(payload.pickup.address ?? payload.pickup.street1 ?? payload.pickup.name ?? '—');
        }

        const waypoints = typeof payload.waypoints?.toArray === 'function' ? payload.waypoints.toArray() : Array.isArray(payload.waypoints) ? payload.waypoints : [];

        for (const wp of waypoints) {
            const place = wp.place ?? wp;
            stops.push(place.address ?? place.street1 ?? place.name ?? '—');
        }

        if (payload.dropoff) {
            stops.push(payload.dropoff.address ?? payload.dropoff.street1 ?? payload.dropoff.name ?? '—');
        }

        if (stops.length === 0) {
            if (order.pickup_name) stops.push(order.pickup_name);
            if (order.dropoff_name) stops.push(order.dropoff_name);
        }

        return stops.map((address, i) => ({ label: String.fromCharCode(65 + i), address }));
    }

    // ── Advanced filter panel ─────────────────────────────────────────────────

    @action toggleAdvanced() {
        this.showAdvanced = !this.showAdvanced;
    }

    @action setAdvancedCountry(value) {
        this.advancedCountry = value || null;
    }

    @action setAdvancedType(event) {
        this.advancedType = event.target.value || null;
    }

    @action setAdvancedStatus(event) {
        this.advancedStatus = event.target.value || null;
    }

    @action setAdvancedDate(event) {
        this.advancedDate = event.target.value || null;
    }

    @action clearAdvancedFilters() {
        this.advancedCountry = null;
        this.advancedType = null;
        this.advancedStatus = null;
        this.advancedDate = null;
    }

    get hasAdvancedFilters() {
        return !!(this.advancedCountry || this.advancedType || this.advancedStatus || this.advancedDate);
    }

    /** Unique order types derived from the current order pool. */
    get availableTypes() {
        const orders = this.args.orders ?? [];
        const types = [...new Set(orders.map((o) => o.type).filter(Boolean))].sort();
        return types;
    }

    /** Unique order statuses derived from the current order pool. */
    get availableStatuses() {
        const orders = this.args.orders ?? [];
        const statuses = [...new Set(orders.map((o) => o.status).filter(Boolean))].sort();
        return statuses;
    }

    // ── Filtered orders ───────────────────────────────────────────────────────

    get filteredOrders() {
        let orders = this.args.orders ?? [];

        // ── Text search ───────────────────────────────────────────────────────
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

        // ── Quick filter chip ─────────────────────────────────────────────────
        if (this.orderFilter === 'scheduled') {
            orders = orders.filter((o) => !!o.scheduled_at);
        } else if (this.orderFilter === 'urgent') {
            orders = orders.filter((o) => (o.orchestrator_priority ?? 0) >= 75);
        } else if (this.orderFilter === 'today') {
            const today = new Date().toDateString();
            orders = orders.filter((o) => o.scheduled_at && new Date(o.scheduled_at).toDateString() === today);
        } else if (this.orderFilter === 'unassigned') {
            orders = orders.filter((o) => !o.vehicle_assigned_uuid && !o.driver_assigned_uuid);
        } else if (this.orderFilter === 'imported') {
            orders = orders.filter((o) => o.meta?.imported_via_orchestrator);
        }

        // ── Advanced filters ──────────────────────────────────────────────────
        if (this.advancedCountry) {
            const country = this.advancedCountry.toLowerCase();
            orders = orders.filter((o) => {
                const pickupCountry = (o.payload?.pickup?.country ?? '').toLowerCase();
                const dropoffCountry = (o.payload?.dropoff?.country ?? '').toLowerCase();
                const pickupAddr = (o.payload?.pickup?.address ?? '').toLowerCase();
                return pickupCountry === country || dropoffCountry === country || pickupAddr.includes(country);
            });
        }

        if (this.advancedType) {
            orders = orders.filter((o) => o.type === this.advancedType);
        }

        if (this.advancedStatus) {
            orders = orders.filter((o) => o.status === this.advancedStatus);
        }

        if (this.advancedDate) {
            const filterDate = new Date(this.advancedDate).toDateString();
            orders = orders.filter((o) => o.scheduled_at && new Date(o.scheduled_at).toDateString() === filterDate);
        }

        return orders;
    }

    get selectedOrderIdsArray() {
        return [...(this.args.selectedOrderIds ?? new Set())];
    }

    // ── Card field resolution ─────────────────────────────────────────────────

    /**
     * resolvedCardFields — @action so Glimmer allows it to be invoked with
     * arguments from HBS:  {{#each (this.resolvedCardFields order) as |field|}}
     */
    @action resolvedCardFields(order) {
        const cardFields = this.args.cardFields;

        if (!cardFields) {
            return DEFAULT_FIELDS.map(({ key, icon, iconClass, highlight }) => {
                const { value, label } = this._resolveStandardFieldFull(order, key);
                if (!value) return null;
                return { label, value, icon, iconClass, highlight };
            }).filter(Boolean);
        }

        const fields = [];

        for (const key of cardFields.standard ?? []) {
            const { value, label } = this._resolveStandardFieldFull(order, key);
            if (!value) continue;
            const meta = FIELD_META[key] ?? {};
            fields.push({ label, value, ...meta });
        }

        const configUuid = order.order_config_uuid;
        if (configUuid && cardFields.byConfig?.[configUuid]) {
            for (const fieldKey of cardFields.byConfig[configUuid]) {
                let label = fieldKey;
                let value = null;

                const cfvCollection = order.custom_field_values ?? order.customFieldValues ?? [];
                const cfvArray = typeof cfvCollection?.toArray === 'function' ? cfvCollection.toArray() : Array.isArray(cfvCollection) ? cfvCollection : [];

                for (const cfv of cfvArray) {
                    const cf = cfv.custom_field ?? (typeof cfv.customField?.get === 'function' ? cfv.customField : null) ?? cfv.customField ?? null;

                    const cfName = cf?.name ?? cf?.get?.('name') ?? '';
                    const cfLabel = cf?.label ?? cf?.get?.('label') ?? '';

                    if (cfName !== fieldKey && cfLabel !== fieldKey) continue;

                    const rawVal = cfv.value ?? null;
                    if (rawVal !== null && rawVal !== undefined) {
                        label = cfLabel || cfName || fieldKey;
                        value = typeof rawVal === 'object' ? JSON.stringify(rawVal) : String(rawVal);
                    }
                    break;
                }

                if (value !== null && value !== '') {
                    fields.push({ label, value });
                }
            }
        }

        for (const key of cardFields.meta ?? []) {
            const val = order.meta?.[key];
            if (val !== undefined && val !== null) {
                fields.push({ label: key, value: String(val) });
            }
        }

        return fields;
    }

    @action priorityBadgeStatus(priority) {
        if (priority >= 75) return 'error';
        if (priority >= 50) return 'warning';
        return 'info';
    }

    _resolveStandardFieldFull(order, key) {
        const t = (k) => {
            try {
                return this.intl.t(`orchestrator.${k}`);
            } catch {
                return k;
            }
        };
        const map = {
            tracking: { label: t('field-tracking'), value: order.tracking ?? order.public_id },
            status: { label: t('field-status'), value: order.status },
            scheduled_at: { label: t('scheduled'), value: order.scheduled_at ? this._formatDate(order.scheduled_at) : null },
            customer: { label: t('customer'), value: order.customer?.name },
            type: { label: t('field-type'), value: order.type },
            notes: { label: t('field-notes'), value: order.notes },
            priority: { label: t('field-priority'), value: order.orchestrator_priority != null ? String(order.orchestrator_priority) : null },
            dropoff: { label: t('dropoff'), value: order.payload?.dropoff?.address ?? order.dropoff_name },
            pickup: { label: t('pickup'), value: order.payload?.pickup?.address ?? order.pickup_name },
            driver_assigned: { label: t('driver-assigned'), value: order.driver_assigned?.name },
            vehicle_assigned: { label: t('vehicle-assigned'), value: order.vehicle_assigned?.display_name ?? order.vehicle_assigned?.name },
            created_at: { label: t('created'), value: order.created_at ? this._formatDate(order.created_at) : null },
        };
        return map[key] ?? { label: key, value: order[key] != null ? String(order[key]) : null };
    }

    _formatDate(date) {
        if (!date) return null;
        try {
            return new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(date));
        } catch {
            return String(date);
        }
    }
}
