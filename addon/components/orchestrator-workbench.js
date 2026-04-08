import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { getOwner } from '@ember/application';

// Route colours for up to 20 vehicles — cycles if more
const ROUTE_COLORS = [
    '#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6',
    '#06B6D4', '#84CC16', '#F97316', '#EC4899', '#6366F1',
    '#14B8A6', '#A3E635', '#FB923C', '#F43F5E', '#A78BFA',
    '#22D3EE', '#4ADE80', '#FBBF24', '#E879F9', '#60A5FA',
];

/**
 * OrchestratorWorkbenchComponent
 *
 * The Dispatcher Workbench — the primary UI for the Orchestrator module.
 * Provides:
 *
 *   - Order Pool (left): filterable, searchable list of unassigned orders
 *     with drag-and-drop support and multi-select for targeted runs.
 *   - Interactive Map (centre): Leaflet map showing order markers, driver
 *     positions, and proposed route polylines.
 *   - Driver / Route Panel (right): available drivers pre-run; per-vehicle
 *     route cards with stop sequences and ETA estimates post-run.
 *
 * Modes:
 *   - 'allocate': assign unassigned orders to drivers (default)
 *   - 'optimize': re-sequence already-assigned orders for minimum distance/time
 */
export default class OrchestratorWorkbenchComponent extends Component {
    @service store;
    @service fetch;
    @service notifications;
    @service intl;
    @service modalsManager;
    @service('order-allocation') allocationService;

    // ── Data ──────────────────────────────────────────────────────────────────

    /** All unassigned orders loaded from the store. */
    @tracked unassignedOrders = [];

    /** Available vehicles (with online drivers). */
    @tracked availableVehicles = [];

    /** Available engine list from the backend. */
    @tracked availableEngines = [];

    // ── Plan state ────────────────────────────────────────────────────────────

    /** The proposed allocation/optimization plan — null until a run completes. */
    @tracked proposedPlan = null;

    /** Per-vehicle route summaries (distance, duration) from the engine. */
    @tracked routeSummaries = {};

    /** Orders that the engine could not assign. */
    @tracked unassignedAfterRun = [];

    /** Whether the workbench is in "committed" state (plan applied). */
    @tracked isCommitted = false;

    /** Tracks manual drag-and-drop overrides made by the dispatcher. */
    @tracked manualOverrides = {};

    // ── UI state ──────────────────────────────────────────────────────────────

    /** Whether the options panel is visible. */
    @tracked showOptionsPanel = false;

    /** Set of selected order public_ids (for targeted runs). */
    @tracked selectedOrderIds = new Set();

    /** Set of selected vehicle public_ids (for targeted runs). */
    @tracked selectedVehicleIds = new Set();

    /** Set of expanded route card vehicle IDs. */
    @tracked expandedRouteCards = new Set();

    /** Order pool search string. */
    @tracked orderSearch = '';

    /** Order pool filter: 'all' | 'scheduled' | 'urgent' | 'imported'. */
    @tracked orderFilter = 'all';

    // ── Run options ───────────────────────────────────────────────────────────

    @tracked selectedMode = 'allocate';
    @tracked selectedEngine = 'vroom';
    @tracked balanceWorkload = false;
    @tracked respectSkills = true;
    @tracked respectCapacity = true;
    @tracked returnToDepot = false;

    // ── Map ───────────────────────────────────────────────────────────────────

    @tracked mapReady = true;
    @tracked mapCenter = { lat: 0, lng: 0 };
    @tracked mapZoom = 11;
    @tracked leafletMap = null;

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    constructor() {
        super(...arguments);
        this.loadData.perform();
        this.loadEngines.perform();
    }

    // ── Data loading ──────────────────────────────────────────────────────────

    @task *loadData() {
        yield Promise.all([
            this.loadUnassignedOrders.perform(),
            this.loadAvailableVehicles.perform(),
        ]);
    }

    @task *loadUnassignedOrders() {
        try {
            const orders = yield this.store.query('order', {
                unassigned: true,
                status:     'created',
                limit:      500,
                with:       'payload.dropoff,payload.pickup,payload.waypoints',
            });
            this.unassignedOrders = orders.toArray();
            this._centerMapOnOrders();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *loadAvailableVehicles() {
        try {
            const vehicles = yield this.store.query('vehicle', {
                with_online_driver: true,
                limit: 300,
            });
            this.availableVehicles = vehicles.toArray();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *loadEngines() {
        try {
            const result = yield this.fetch.get('fleet-ops/allocation/engines');
            this.availableEngines = result?.engines ?? [{ id: 'vroom', name: 'VROOM' }];
        } catch {
            this.availableEngines = [{ id: 'vroom', name: 'VROOM' }];
        }
    }

    // ── Orchestration actions ─────────────────────────────────────────────────

    /**
     * Run the orchestration engine and populate the proposed plan.
     */
    @task *runOrchestration() {
        this.proposedPlan       = null;
        this.isCommitted        = false;
        this.manualOverrides    = {};
        this.routeSummaries     = {};
        this.unassignedAfterRun = [];

        // Use selected orders/vehicles if any, otherwise run against all
        const orderIds   = this.selectedOrderIds.size > 0
            ? [...this.selectedOrderIds]
            : this.unassignedOrders.map((o) => o.public_id);

        const vehicleIds = this.selectedVehicleIds.size > 0
            ? [...this.selectedVehicleIds]
            : this.availableVehicles.map((v) => v.public_id);

        try {
            const result = yield this.fetch.post('fleet-ops/allocation/run', {
                order_ids:   orderIds,
                vehicle_ids: vehicleIds,
                mode:        this.selectedMode,
                options: {
                    engine:           this.selectedEngine,
                    balance_workload: this.balanceWorkload,
                    respect_skills:   this.respectSkills,
                    respect_capacity: this.respectCapacity,
                    return_to_depot:  this.returnToDepot,
                    geometry:         true, // request polylines
                },
            });

            this.proposedPlan       = result.assignments ?? [];
            this.unassignedAfterRun = result.unassigned ?? [];

            // Store per-vehicle summaries keyed by vehicle_id
            const summaries = {};
            for (const assignment of this.proposedPlan) {
                if (assignment.vehicle_id && !summaries[assignment.vehicle_id]) {
                    summaries[assignment.vehicle_id] = {
                        duration: assignment.route_duration ?? null,
                        distance: assignment.route_distance ?? null,
                    };
                }
            }
            this.routeSummaries = summaries;

            // Auto-expand all route cards
            this.expandedRouteCards = new Set(
                Object.keys(this._groupByVehicle(this.proposedPlan))
            );
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Commit the (possibly modified) proposed plan.
     */
    @task *commitPlan() {
        if (!this.proposedPlan?.length) {
            return;
        }

        // Apply manual overrides before committing
        const finalAssignments = this.proposedPlan.map((assignment) => {
            const override = this.manualOverrides[assignment.order_id];
            return override ? { ...assignment, ...override } : assignment;
        });

        try {
            yield this.fetch.post('fleet-ops/allocation/commit', {
                assignments: finalAssignments,
            });

            this.notifications.success(this.intl.t('orchestrator.committed'));
            this.isCommitted        = true;
            this.proposedPlan       = null;
            this.unassignedAfterRun = [];
            this.manualOverrides    = {};
            this.expandedRouteCards = new Set();
            yield this.loadData.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Discard the proposed plan without committing.
     */
    @action discardPlan() {
        this.proposedPlan       = null;
        this.unassignedAfterRun = [];
        this.manualOverrides    = {};
        this.isCommitted        = false;
        this.expandedRouteCards = new Set();
        this.routeSummaries     = {};
    }

    // ── Import ────────────────────────────────────────────────────────────────

    @action openImportModal() {
        this.modalsManager.show('modals/orchestrator-import', {
            title:       this.intl.t('orchestrator.import-orders'),
            acceptButtonText: this.intl.t('orchestrator.import-confirm'),
            onImportComplete: () => {
                this.loadUnassignedOrders.perform();
            },
        });
    }

    // ── Options panel ─────────────────────────────────────────────────────────

    @action toggleOptionsPanel() {
        this.showOptionsPanel = !this.showOptionsPanel;
    }

    @action setMode(mode) {
        this.selectedMode = mode;
    }

    @action setEngine(engineId) {
        this.selectedEngine = engineId;
    }

    // ── Order selection ───────────────────────────────────────────────────────

    @action toggleOrderSelection(order) {
        const ids = new Set(this.selectedOrderIds);
        if (ids.has(order.public_id)) {
            ids.delete(order.public_id);
        } else {
            ids.add(order.public_id);
        }
        this.selectedOrderIds = ids;
    }

    @action clearSelection() {
        this.selectedOrderIds = new Set();
    }

    isOrderSelected(order) {
        return this.selectedOrderIds.has(order.public_id);
    }

    // ── Driver selection ──────────────────────────────────────────────────────

    @action toggleDriverSelection(vehicle) {
        const ids = new Set(this.selectedVehicleIds);
        if (ids.has(vehicle.public_id)) {
            ids.delete(vehicle.public_id);
        } else {
            ids.add(vehicle.public_id);
        }
        this.selectedVehicleIds = ids;
    }

    @action clearDriverSelection() {
        this.selectedVehicleIds = new Set();
    }

    isDriverSelected(vehicle) {
        return this.selectedVehicleIds.has(vehicle.public_id);
    }

    // ── Route card expand/collapse ────────────────────────────────────────────

    @action toggleRouteCard(vehicleId) {
        const expanded = new Set(this.expandedRouteCards);
        if (expanded.has(vehicleId)) {
            expanded.delete(vehicleId);
        } else {
            expanded.add(vehicleId);
        }
        this.expandedRouteCards = expanded;
    }

    isRouteCardExpanded(vehicleId) {
        return this.expandedRouteCards.has(vehicleId);
    }

    // ── Drag-and-drop ─────────────────────────────────────────────────────────

    @tracked _draggingOrder = null;

    @action onOrderDragStart(order, event) {
        this._draggingOrder = order;
        event.dataTransfer.setData('text/plain', order.public_id);
        event.dataTransfer.effectAllowed = 'move';
    }

    @action onAssignedOrderDragStart(order, event) {
        this._draggingOrder = order;
        event.dataTransfer.setData('text/plain', order.public_id);
        event.dataTransfer.effectAllowed = 'move';
    }

    @action onDragOver(event) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
    }

    /**
     * Handle a drop onto a vehicle route card.
     * Reassigns the dragged order to the target vehicle/driver.
     */
    @action onDropOnVehicle(vehicleId, driverId, event) {
        event.preventDefault();
        const orderId = event.dataTransfer.getData('text/plain');
        if (!orderId) return;

        // Record the override
        this.manualOverrides = {
            ...this.manualOverrides,
            [orderId]: { vehicle_id: vehicleId, driver_id: driverId, _overridden: true },
        };

        // If the order is currently in the unassigned pool, add it to the plan
        const existingAssignment = this.proposedPlan?.find((a) => a.order_id === orderId);

        if (existingAssignment) {
            // Move existing assignment to new vehicle
            this.proposedPlan = this.proposedPlan.map((a) => {
                if (a.order_id === orderId) {
                    return { ...a, vehicle_id: vehicleId, driver_id: driverId, _overridden: true };
                }
                return a;
            });
        } else {
            // Add a new manual assignment from the order pool
            const order  = this.unassignedOrders.find((o) => o.public_id === orderId);
            const vehicle = this.availableVehicles.find((v) => v.public_id === vehicleId);
            if (order && vehicle) {
                const newAssignment = {
                    order_id:   orderId,
                    vehicle_id: vehicleId,
                    driver_id:  driverId,
                    sequence:   (this.proposedPlan?.filter((a) => a.vehicle_id === vehicleId).length ?? 0) + 1,
                    _overridden: true,
                };
                this.proposedPlan = [...(this.proposedPlan ?? []), newAssignment];
            }
        }

        this._draggingOrder = null;
    }

    // ── Map ───────────────────────────────────────────────────────────────────

    @action onMapLoad(map) {
        this.leafletMap = map;
    }

    _centerMapOnOrders() {
        const orders = this.unassignedOrders;
        if (!orders.length) return;

        const lats = orders
            .map((o) => o.payload?.dropoff?.location?.coordinates?.[1])
            .filter(Boolean);
        const lngs = orders
            .map((o) => o.payload?.dropoff?.location?.coordinates?.[0])
            .filter(Boolean);

        if (!lats.length) return;

        this.mapCenter = {
            lat: lats.reduce((a, b) => a + b, 0) / lats.length,
            lng: lngs.reduce((a, b) => a + b, 0) / lngs.length,
        };
    }

    get tileSourceUrl() {
        return 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
    }

    orderMarkerIcon(order) {
        const isSelected = this.isOrderSelected(order);
        const color = isSelected ? '#3B82F6' : '#6B7280';
        return {
            html: `<div style="width:10px;height:10px;border-radius:50%;background:${color};border:2px solid white;box-shadow:0 1px 3px rgba(0,0,0,.4)"></div>`,
            iconSize: [10, 10],
            iconAnchor: [5, 5],
            className: '',
        };
    }

    driverMarkerIcon(vehicle) {
        return {
            html: `<div style="width:14px;height:14px;border-radius:50%;background:#10B981;border:2px solid white;box-shadow:0 1px 3px rgba(0,0,0,.4)"></div>`,
            iconSize: [14, 14],
            iconAnchor: [7, 7],
            className: '',
        };
    }

    // ── Filters ───────────────────────────────────────────────────────────────

    @action setOrderFilter(filter) {
        this.orderFilter = filter;
    }

    get filteredOrders() {
        let orders = this.unassignedOrders;

        // Text search
        if (this.orderSearch) {
            const q = this.orderSearch.toLowerCase();
            orders = orders.filter((o) =>
                o.public_id?.toLowerCase().includes(q) ||
                o.payload?.dropoff?.address?.toLowerCase().includes(q)
            );
        }

        // Filter chips
        if (this.orderFilter === 'scheduled') {
            orders = orders.filter((o) => o.scheduled_at);
        } else if (this.orderFilter === 'urgent') {
            orders = orders.filter((o) => (o.orchestrator_priority ?? 0) >= 75);
        } else if (this.orderFilter === 'imported') {
            orders = orders.filter((o) => o.meta?.imported_via_orchestrator);
        }

        return orders;
    }

    // ── Computed helpers ──────────────────────────────────────────────────────

    get selectedOrders() {
        return this.unassignedOrders.filter((o) => this.selectedOrderIds.has(o.public_id));
    }

    get selectedDrivers() {
        return this.availableVehicles.filter((v) => this.selectedVehicleIds.has(v.public_id));
    }

    get hasProposedPlan() {
        return Array.isArray(this.proposedPlan) && this.proposedPlan.length > 0;
    }

    get hasUnassigned() {
        return this.unassignedAfterRun.length > 0;
    }

    /**
     * Group proposed assignments by vehicle_id.
     * Returns an array of { vehicle, driver, orders, routeColor, summary, routePolyline }.
     */
    get planByVehicle() {
        if (!this.proposedPlan?.length) return [];

        const grouped = this._groupByVehicle(this.proposedPlan);
        return Object.entries(grouped).map(([vehicleId, group], index) => ({
            ...group,
            routeColor:   ROUTE_COLORS[index % ROUTE_COLORS.length],
            summary:      this.routeSummaries[vehicleId] ?? {},
            routePolyline: null, // populated when geometry is returned by engine
        }));
    }

    _groupByVehicle(assignments) {
        const groups = {};
        for (const assignment of assignments) {
            const { vehicle_id } = assignment;
            if (!groups[vehicle_id]) {
                const vehicle = this.availableVehicles.find((v) => v.public_id === vehicle_id);
                groups[vehicle_id] = {
                    vehicle,
                    driver: vehicle?.driver,
                    orders: [],
                };
            }
            const order = this.unassignedOrders.find((o) => o.public_id === assignment.order_id);
            if (order) {
                groups[vehicle_id].orders.push({
                    order,
                    sequence:    assignment.sequence,
                    arrival:     assignment.arrival,
                    _overridden: assignment._overridden ?? false,
                });
            }
        }
        // Sort stops by sequence within each group
        for (const g of Object.values(groups)) {
            g.orders.sort((a, b) => (a.sequence ?? 0) - (b.sequence ?? 0));
        }
        return groups;
    }

    get modeOptions() {
        return [
            { value: 'allocate', label: this.intl.t('orchestrator.mode-allocate') },
            { value: 'optimize', label: this.intl.t('orchestrator.mode-optimize') },
        ];
    }

    priorityStatus(priority) {
        if (priority >= 75) return 'error';
        if (priority >= 50) return 'warning';
        return 'info';
    }

    // ── Formatters ────────────────────────────────────────────────────────────

    formatDuration(seconds) {
        if (!seconds) return '';
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        return h > 0 ? `${h}h ${m}m` : `${m}m`;
    }

    formatDistance(metres) {
        if (!metres) return '';
        return metres >= 1000
            ? `${(metres / 1000).toFixed(1)} km`
            : `${metres} m`;
    }

    formatUnixTime(unix) {
        if (!unix) return '';
        const d = new Date(unix * 1000);
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
}
