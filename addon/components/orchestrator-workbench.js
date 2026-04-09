import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { colorForId, routeStyleForStatus, waypointIconHtml } from '../utils/route-colors';

/**
 * OrchestratorWorkbenchComponent
 *
 * The Dispatcher Workbench — primary UI for the Orchestrator module.
 *
 * Layout:
 *   Left panel   — Orchestrator::OrderPool (filterable order list)
 *   Centre        — Leaflet map + optional phase builder panel
 *   Right panel  — Orchestrator::ResourcePanel (pre-run) or
 *                  Orchestrator::PlanViewer (post-run)
 *
 * Modes (via PhaseBuilder):
 *   assign_vehicles — allocate orders to vehicles using VROOM
 *   assign_drivers  — match drivers to vehicles (greedy shift-aware)
 *   optimize_routes — re-sequence stops for minimum distance/time
 *   allocate        — legacy single-pass assign driver+vehicle (default)
 *
 * The workbench delegates rendering of each panel to dedicated sub-components
 * and only owns cross-cutting state: data, plan, selections, map, and phases.
 */
export default class OrchestratorWorkbenchComponent extends Component {
    @service store;
    @service fetch;
    @service notifications;
    @service intl;
    @service modalsManager;
    @service location;
    @service('order-allocation') allocationService;

    // ── Data ──────────────────────────────────────────────────────────────────

    @tracked unassignedOrders = [];
    @tracked availableVehicles = [];
    @tracked availableDrivers = [];
    @tracked availableEngines = [];

    // ── Plan state ────────────────────────────────────────────────────────────

    @tracked proposedPlan = null;
    @tracked routeSummaries = {};
    @tracked unassignedAfterRun = [];
    @tracked orchestratorRunMessage = null;
    @tracked isCommitted = false;
    @tracked manualOverrides = {};

    // ── Phase builder ─────────────────────────────────────────────────────────

    /**
     * User-composed list of phases to execute in sequence.
     * Each phase: { id, mode, label, engine, orderStatuses, balanceWorkload,
     *               respectSkills, respectCapacity, returnToDepot, autoCommit }
     */
    @tracked phases = [];

    /** Whether the phase builder panel is visible (replaces old options panel). */
    @tracked showPhaseBuilder = false;

    /** Whether the card fields settings panel is visible. */
    @tracked showCardFieldsSettings = false;

    // ── Configurable card fields ──────────────────────────────────────────────

    /**
     * Loaded from company settings. Shape:
     *   { standard: string[], byConfig: { [configUuid]: string[] }, meta: string[] }
     */
    @tracked cardFields = null;

    // ── UI state ──────────────────────────────────────────────────────────────

    @tracked leftPanelCollapsed = false;
    @tracked rightPanelCollapsed = false;
    @tracked leftPanelWidth = 290;
    @tracked rightPanelWidth = 330;

    // ── Resize state (not tracked — only used during drag) ────────────────────
    _resizing = null;

    @tracked selectedOrderIds = new Set();
    @tracked selectedVehicleIds = new Set();
    @tracked selectedDriverIds = new Set();

    // ── Map ───────────────────────────────────────────────────────────────────

    @tracked mapCenter = { lat: 1.369, lng: 103.8864 };
    @tracked mapZoom = 11;
    @tracked leafletMap = null;

    // ── Drag ──────────────────────────────────────────────────────────────────

    @tracked _draggingOrder = null;

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    constructor() {
        super(...arguments);
        const lat = this.location.getLatitude();
        const lng = this.location.getLongitude();
        if (lat != null && lng != null) {
            this.mapCenter = { lat, lng };
        }
        this.location
            .getUserLocation()
            .then(({ latitude, longitude }) => {
                this.mapCenter = { lat: latitude, lng: longitude };
                if (this.leafletMap?.setView) {
                    this.leafletMap.setView([latitude, longitude], this.mapZoom);
                }
            })
            .catch(() => {});

        this.loadData.perform();
        this.loadEngines.perform();
        this.loadCardFields.perform();
    }

    // ── Data loading ──────────────────────────────────────────────────────────

    @task *loadData() {
        yield Promise.all([this.loadOrders.perform(), this.loadAvailableVehicles.perform(), this.loadAvailableDrivers.perform()]);
    }

    @task *loadOrders() {
        try {
            const orders = yield this.store.query('order', {
                unassigned: true,
                status: 'created,dispatched,started',
                limit: 500,
                with: 'payload.dropoff,payload.pickup,payload.waypoints,customFields',
            });
            this.unassignedOrders = orders.toArray();
            this._centerMapOnOrders();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *loadAvailableVehicles() {
        try {
            const vehicles = yield this.store.query('vehicle', { limit: 300 });
            this.availableVehicles = vehicles.toArray();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *loadAvailableDrivers() {
        try {
            const drivers = yield this.store.query('driver', { limit: 300 });
            this.availableDrivers = drivers.toArray();
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

    @task *loadCardFields() {
        try {
            const result = yield this.fetch.get('fleet-ops/settings/orchestrator-card-fields').catch(() => null);
            this.cardFields = result?.settings ?? null;
        } catch {
            this.cardFields = null;
        }
    }

    // ── Orchestration ─────────────────────────────────────────────────────────

    /**
     * Run all configured phases in sequence. If no phases are configured,
     * falls back to a single legacy 'allocate' run.
     */
    @task *runOrchestration() {
        this.proposedPlan = null;
        this.isCommitted = false;
        this.manualOverrides = {};
        this.routeSummaries = {};
        this.unassignedAfterRun = [];
        this.orchestratorRunMessage = null;

        const phasesToRun = this.phases.length > 0 ? this.phases : [this._legacyPhase()];

        yield this._executePhases.perform(phasesToRun);
    }

    @task *_executePhases(phases) {
        for (const phase of phases) {
            yield this._runSinglePhase.perform(phase);
            // If phase has autoCommit, commit immediately before next phase
            if (phase.autoCommit && this.proposedPlan?.length) {
                yield this.commitPlan.perform();
            }
        }
    }

    @task *_runSinglePhase(phase) {
        const orderIds = this.selectedOrderIds.size > 0 ? [...this.selectedOrderIds] : this.unassignedOrders.map((o) => o.public_id);

        const vehicleIdsFromVehicleTab = [...this.selectedVehicleIds];
        const vehicleIdsFromDriverTab = [...this.selectedDriverIds]
            .map((driverId) => {
                const driver = this.availableDrivers.find((d) => d.public_id === driverId);
                return driver?.vehicle?.public_id ?? null;
            })
            .filter(Boolean);

        const resolvedVehicleIds = [...new Set([...vehicleIdsFromVehicleTab, ...vehicleIdsFromDriverTab])];
        const vehicleIds = resolvedVehicleIds.length > 0 ? resolvedVehicleIds : this.availableVehicles.map((v) => v.public_id);

        const driverIds = this.selectedDriverIds.size > 0 ? [...this.selectedDriverIds] : null;

        try {
            const payload = {
                order_ids: orderIds,
                vehicle_ids: vehicleIds,
                mode: phase.mode,
                order_statuses: phase.orderStatuses ?? ['created'],
                options: {
                    engine: phase.engine ?? 'vroom',
                    balance_workload: phase.balanceWorkload ?? false,
                    respect_skills: phase.respectSkills ?? true,
                    respect_capacity: phase.respectCapacity ?? true,
                    return_to_depot: phase.returnToDepot ?? false,
                },
            };
            if (driverIds) {
                payload.driver_ids = driverIds;
            }

            const result = yield this.fetch.post('fleet-ops/allocation/run', payload);

            // Merge results — later phases can override earlier assignments
            const newAssignments = result.assignments ?? [];
            const existing = this.proposedPlan ?? [];
            const merged = [...existing];
            for (const assignment of newAssignments) {
                const idx = merged.findIndex((a) => a.order_id === assignment.order_id);
                if (idx >= 0) {
                    merged[idx] = { ...merged[idx], ...assignment };
                } else {
                    merged.push(assignment);
                }
            }
            this.proposedPlan = merged;
            this.unassignedAfterRun = result.unassigned ?? [];
            if (result.message) {
                this.orchestratorRunMessage = result.message;
            }

            // Update route summaries
            const summaries = { ...this.routeSummaries };
            for (const assignment of newAssignments) {
                if (assignment.vehicle_id && !summaries[assignment.vehicle_id]) {
                    summaries[assignment.vehicle_id] = {
                        duration: assignment.route_duration ?? null,
                        distance: assignment.route_distance ?? null,
                    };
                }
            }
            this.routeSummaries = summaries;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /** Commit the (possibly modified) proposed plan and generate manifests. */
    @task *commitPlan() {
        if (!this.proposedPlan?.length) return;

        const finalAssignments = this.proposedPlan.map((assignment) => {
            const override = this.manualOverrides[assignment.order_id];
            return override ? { ...assignment, ...override } : assignment;
        });

        try {
            yield this.fetch.post('fleet-ops/allocation/commit', {
                assignments: finalAssignments,
            });

            this.notifications.success(this.intl.t('orchestrator.committed'));
            this.isCommitted = true;
            this.proposedPlan = null;
            this.unassignedAfterRun = [];
            this.manualOverrides = {};
            this.routeSummaries = {};
            yield this.loadData.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action discardPlan() {
        this.proposedPlan = null;
        this.unassignedAfterRun = [];
        this.manualOverrides = {};
        this.isCommitted = false;
        this.routeSummaries = {};
        this.orchestratorRunMessage = null;
    }

    // ── Phase management ──────────────────────────────────────────────────────

    @action onPhasesChange(phases) {
        this.phases = phases;
    }

    @action onRunPhases(phases) {
        this.phases = phases;
        this.runOrchestration.perform();
    }

    _legacyPhase() {
        return {
            id: 'legacy',
            mode: 'allocate',
            label: 'Allocate',
            engine: 'vroom',
            orderStatuses: ['created'],
            balanceWorkload: false,
            respectSkills: true,
            respectCapacity: true,
            returnToDepot: false,
            autoCommit: false,
        };
    }

    // ── Panel toggles ─────────────────────────────────────────────────────────

    @action toggleLeftPanel() {
        this.leftPanelCollapsed = !this.leftPanelCollapsed;
    }
    @action toggleRightPanel() {
        this.rightPanelCollapsed = !this.rightPanelCollapsed;
    }

    @action togglePhaseBuilder() {
        this.showPhaseBuilder = !this.showPhaseBuilder;
        this.showCardFieldsSettings = false;
    }

    @action toggleCardFieldsSettings() {
        this.showCardFieldsSettings = !this.showCardFieldsSettings;
        this.showPhaseBuilder = false;
    }

    @action onCardFieldsSaved() {
        this.showCardFieldsSettings = false;
        this.loadCardFields.perform();
    }

    // ── Import ────────────────────────────────────────────────────────────────

    @action openImportModal() {
        this.modalsManager.show('modals/orchestrator-import', {
            title: this.intl.t('orchestrator.import-orders'),
            acceptButtonText: this.intl.t('orchestrator.import-confirm'),
            onImportComplete: () => this.loadOrders.perform(),
        });
    }

    // ── Run message ───────────────────────────────────────────────────────────

    @action dismissRunMessage() {
        this.orchestratorRunMessage = null;
    }

    // ── Order selection ───────────────────────────────────────────────────────

    @action toggleOrderSelection(order) {
        const ids = new Set(this.selectedOrderIds);
        ids.has(order.public_id) ? ids.delete(order.public_id) : ids.add(order.public_id);
        this.selectedOrderIds = ids;
    }

    @action clearOrderSelection() {
        this.selectedOrderIds = new Set();
    }

    // ── Vehicle selection ─────────────────────────────────────────────────────

    @action toggleVehicleSelection(vehicle) {
        const ids = new Set(this.selectedVehicleIds);
        ids.has(vehicle.public_id) ? ids.delete(vehicle.public_id) : ids.add(vehicle.public_id);
        this.selectedVehicleIds = ids;
    }

    @action clearVehicleSelection() {
        this.selectedVehicleIds = new Set();
    }

    // ── Driver selection ──────────────────────────────────────────────────────

    @action toggleDriverSelection(driver) {
        const ids = new Set(this.selectedDriverIds);
        ids.has(driver.public_id) ? ids.delete(driver.public_id) : ids.add(driver.public_id);
        this.selectedDriverIds = ids;
    }

    @action clearDriverSelection() {
        this.selectedDriverIds = new Set();
        this.selectedVehicleIds = new Set();
    }

    // ── Drag-and-drop ─────────────────────────────────────────────────────────

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

    @action onDropOnVehicle(vehicleId, driverId, event) {
        event.preventDefault();
        const orderId = event.dataTransfer.getData('text/plain');
        if (!orderId) return;

        this.manualOverrides = {
            ...this.manualOverrides,
            [orderId]: { vehicle_id: vehicleId, driver_id: driverId, _overridden: true },
        };

        const existingAssignment = this.proposedPlan?.find((a) => a.order_id === orderId);
        if (existingAssignment) {
            this.proposedPlan = this.proposedPlan.map((a) => (a.order_id === orderId ? { ...a, vehicle_id: vehicleId, driver_id: driverId, _overridden: true } : a));
        } else {
            const order = this.unassignedOrders.find((o) => o.public_id === orderId);
            const vehicle = this.availableVehicles.find((v) => v.public_id === vehicleId);
            if (order && vehicle) {
                const newAssignment = {
                    order_id: orderId,
                    vehicle_id: vehicleId,
                    driver_id: driverId,
                    sequence: (this.proposedPlan?.filter((a) => a.vehicle_id === vehicleId).length ?? 0) + 1,
                    _overridden: true,
                };
                this.proposedPlan = [...(this.proposedPlan ?? []), newAssignment];
            }
        }
        this._draggingOrder = null;
    }

    // ── Map ───────────────────────────────────────────────────────────────────

    @action onMapLoad({ target: map }) {
        this.leafletMap = map;
        map.setView([this.mapCenter.lat, this.mapCenter.lng], this.mapZoom);
    }

    _centerMapOnOrders() {
        const orders = this.unassignedOrders;
        if (!orders.length) return;
        const lats = orders.map((o) => o.payload?.dropoff?.location?.coordinates?.[1]).filter(Boolean);
        const lngs = orders.map((o) => o.payload?.dropoff?.location?.coordinates?.[0]).filter(Boolean);
        if (!lats.length) return;
        const lat = lats.reduce((a, b) => a + b, 0) / lats.length;
        const lng = lngs.reduce((a, b) => a + b, 0) / lngs.length;
        this.mapCenter = { lat, lng };
        if (this.leafletMap?.setView) {
            this.leafletMap.setView([lat, lng], this.mapZoom);
        }
    }

    get tileSourceUrl() {
        const isDark = document.documentElement.classList.contains('dark');
        return isDark ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png' : 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png';
    }

    // ── Computed helpers ──────────────────────────────────────────────────────

    get selectedOrderIdsArray() {
        return [...this.selectedOrderIds];
    }
    get selectedVehicleIdsArray() {
        return [...this.selectedVehicleIds];
    }
    get selectedDriverIdsArray() {
        return [...this.selectedDriverIds];
    }

    get hasProposedPlan() {
        return Array.isArray(this.proposedPlan) && this.proposedPlan.length > 0;
    }

    get hasUnassigned() {
        return this.unassignedAfterRun.length > 0;
    }

    get planByVehicle() {
        if (!this.proposedPlan?.length) return [];
        const grouped = this._groupByVehicle(this.proposedPlan);
        return Object.entries(grouped).map(([vehicleId, group]) => {
            // Derive a deterministic color from the vehicle public_id so the same
            // vehicle always gets the same color across runs and page refreshes.
            const routeColor = colorForId(group.vehicle?.public_id ?? vehicleId);
            // Build the two-layer cased polyline style array for this vehicle's route.
            // The status is taken from the first order in the group (all share a vehicle).
            const firstStatus = group.orders?.[0]?.order?.status ?? 'dispatched';
            const lineStyles = routeStyleForStatus(firstStatus, routeColor);
            return {
                ...group,
                routeColor,
                lineStyles,
                summary: this.routeSummaries[vehicleId] ?? {},
                routePolyline: null,
            };
        });
    }

    /**
     * Build the HTML string for a waypoint marker.
     * Used via the ember-leaflet {{div-icon html=(this.waypointIconHtml ...)}} helper
     * in the HBS template — ember-leaflet's {{div-icon}} constructs the proper
     * L.DivIcon instance, so we only need to return the HTML string here.
     *
     * @param {string} label   - Marker label ("P", "D", or a stop number)
     * @param {string} bgColor - CSS background color
     * @returns {string}
     */
    @action waypointIconHtml(label, bgColor) {
        return waypointIconHtml(label, bgColor);
    }

    _groupByVehicle(assignments) {
        const groups = {};
        for (const assignment of assignments) {
            const { vehicle_id } = assignment;
            if (!groups[vehicle_id]) {
                const vehicle = this.availableVehicles.find((v) => v.public_id === vehicle_id);
                const driver = vehicle?.driver ?? this.availableDrivers.find((d) => d.public_id === assignment.driver_id);
                groups[vehicle_id] = { vehicle, driver, orders: [] };
            }
            const order = this.unassignedOrders.find((o) => o.public_id === assignment.order_id);
            if (order) {
                groups[vehicle_id].orders.push({
                    order,
                    sequence: assignment.sequence,
                    arrival: assignment.arrival,
                    _overridden: assignment._overridden ?? false,
                });
            }
        }
        for (const g of Object.values(groups)) {
            g.orders.sort((a, b) => (a.sequence ?? 0) - (b.sequence ?? 0));
        }
        return groups;
    }

    get phaseCount() {
        return this.phases.length;
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
        return metres >= 1000 ? `${(metres / 1000).toFixed(1)} km` : `${metres} m`;
    }

    formatUnixTime(unix) {
        if (!unix) return '';
        return new Date(unix * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    // ── Panel resize ──────────────────────────────────────────────────────────

    /**
     * Begin dragging the left panel resize handle.
     * Attaches mousemove/mouseup listeners to the document for the duration
     * of the drag so the resize works even when the cursor leaves the handle.
     */
    @action startLeftResize(event) {
        event.preventDefault();
        const startX = event.clientX;
        const startWidth = this.leftPanelWidth;

        const onMove = (e) => {
            const delta = e.clientX - startX;
            const next = Math.min(480, Math.max(200, startWidth + delta));
            this.leftPanelWidth = next;
        };
        const onUp = () => {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
        };

        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    }

    /**
     * Begin dragging the right panel resize handle.
     * Dragging left increases the panel width (delta is inverted).
     */
    @action startRightResize(event) {
        event.preventDefault();
        const startX = event.clientX;
        const startWidth = this.rightPanelWidth;

        const onMove = (e) => {
            const delta = startX - e.clientX; // inverted: drag left = wider
            const next = Math.min(560, Math.max(240, startWidth + delta));
            this.rightPanelWidth = next;
        };
        const onUp = () => {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
        };

        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    }
}
