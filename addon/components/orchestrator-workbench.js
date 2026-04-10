import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { later } from '@ember/runloop';
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
    @service leafletMapManager;
    @service('order-allocation') allocationService;

    /** Routing controls added to the map for the current proposed plan. */
    _routingControls = [];

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
    @tracked runError = null;
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
        // ── Map center diagnostic ──────────────────────────────────────────────
        // Track whether _centerMapOnOrders() has run so getUserLocation() cannot
        // override it even if it resolves before loadOrders completes.
        this._mapCenteredOnOrders = false;

        const lat = this.location.getLatitude();
        const lng = this.location.getLongitude();
        // eslint-disable-next-line no-console
        console.log('[Orchestrator] constructor: location service initial coords =>', { lat, lng });
        if (lat != null && lng != null) {
            this.mapCenter = { lat, lng };
        }
        // getUserLocation resolves to browser/IP geolocation — only use it as a
        // fallback when _centerMapOnOrders() has not yet run (i.e. no orders loaded).
        this.location
            .getUserLocation()
            .then(({ latitude, longitude }) => {
                // eslint-disable-next-line no-console
                console.log('[Orchestrator] getUserLocation resolved =>', { latitude, longitude }, '| _mapCenteredOnOrders =', this._mapCenteredOnOrders);
                if (!this._mapCenteredOnOrders) {
                    // eslint-disable-next-line no-console
                    console.log('[Orchestrator] applying geolocation as map center (no orders centered yet)');
                    this.mapCenter = { lat: latitude, lng: longitude };
                    if (this.leafletMap?.setView) {
                        this.leafletMap.setView([latitude, longitude], this.mapZoom);
                    }
                } else {
                    // eslint-disable-next-line no-console
                    console.log('[Orchestrator] geolocation ignored — map already centered on orders');
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
            // Use the dedicated orchestrator/orders endpoint which returns the
            // OrchestratorOrderResource — a richer payload that includes
            // custom_field_values without impacting the tabular orders view.
            const result = yield this.fetch.get('fleet-ops/orchestrator/orders', {
                unassigned: true,
                limit: 500,
            });
            this.unassignedOrders = result?.orders ?? [];
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
            const result = yield this.fetch.get('fleet-ops/orchestrator/engines');
            this.availableEngines = result?.engines ?? [{ id: 'greedy', name: 'Greedy (built-in)' }];
        } catch {
            this.availableEngines = [{ id: 'greedy', name: 'Greedy (built-in)' }];
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
        // Clear any routing controls from a previous run before starting a new one
        this._clearRoutingControls();
        this.proposedPlan = null;
        this.isCommitted = false;
        this.manualOverrides = {};
        this.routeSummaries = {};
         this.unassignedAfterRun = [];
        this.orchestratorRunMessage = null;
        this.runError = null;
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
                // driver.vehicle is an async belongsTo proxy — use the scalar vehicle_id attr instead
                return driver?.vehicle_id ?? null;
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
                    engine: phase.engine ?? 'greedy',
                    balance_workload: phase.balanceWorkload ?? false,
                    respect_skills: phase.respectSkills ?? true,
                    respect_capacity: phase.respectCapacity ?? true,
                    return_to_depot: phase.returnToDepot ?? false,
                },
            };
            if (driverIds) {
                payload.driver_ids = driverIds;
            }

            const result = yield this.fetch.post('fleet-ops/orchestrator/run', payload);

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
            // If the run returned zero assignments, surface the error/message to
            // the user immediately — otherwise the right panel stays blank with
            // no feedback (PlanViewer is only rendered when hasProposedPlan).
            if (!newAssignments.length) {
                const errorMsg = result.message ?? this.intl.t('orchestrator.no-assignments-returned');
                this.runError = errorMsg;
                this.notifications.warning(errorMsg, { autoClear: true, clearDuration: 6000 });
            } else {
                this.runError = null;
            }
            // Fit the map to the full planned route bounds after the plan is set.
            // Deferred so planByVehicle getter has time to recompute first.
            later(this, () => this._drawRoutingControls(), 200);

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
            const result = yield this.fetch.post('fleet-ops/orchestrator/commit', {
                assignments: finalAssignments,
            });

            this.notifications.success(this.intl.t('orchestrator.committed', { count: result?.committed?.length ?? finalAssignments.length }));
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
        this._clearRoutingControls();
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
            engine: 'greedy',
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
        // Register the map with the service so addRoutingControl / ensureInteractive
        // can resolve it. Without this call, waitForMap() never resolves and
        // routing controls silently time out.
        this.leafletMapManager.setMap(map);
        // If orders have already loaded before the map mounted, re-center now
        // using the same fitBounds strategy as _centerMapOnOrders().
        if (this._mapCenteredOnOrders) {
            this._centerMapOnOrders();
        } else {
            map.setView([this.mapCenter.lat, this.mapCenter.lng], this.mapZoom);
        }
    }

    /**
     * Draw one OSRM routing control per vehicle group onto the Leaflet map.
     * Each routing control takes the ordered stop waypoints [[lat, lng], ...]
     * and asks OSRM for the actual road path, drawing it as a coloured polyline.
     *
     * The leafletMapManager service handles OSRM router setup, map fitting, and
     * routing control lifecycle — consistent with how the rest of the app works.
     *
     * Called automatically (deferred 200ms) after a run completes so that
     * planByVehicle has time to recompute first.
     */
    async _drawRoutingControls() {
        await this._clearRoutingControls();
        const groups = this.planByVehicle;
        if (!groups.length) return;

        for (const group of groups) {
            const waypoints = group.routeWaypoints;
            if (!waypoints || waypoints.length < 2) continue;
            try {
                const control = await this.leafletMapManager.addRoutingControl(waypoints, {
                    // Suppress the routing control's default plain Leaflet markers.
                    // The orchestrator renders its own custom numbered div-icon markers
                    // via planByVehicle in the HBS, so the routing control markers
                    // are redundant and visually incorrect.
                    createMarker: () => null,
                });
                if (control) {
                    this._routingControls.push(control);
                }
            } catch {
                // Non-fatal: routing may fail for individual vehicles if OSRM
                // cannot find a route (e.g. ferry-only legs, missing road data)
            }
        }
    }

    /**
     * Remove all routing controls that were added for the current plan.
     */
    async _clearRoutingControls() {
        for (const control of this._routingControls) {
            try {
                await this.leafletMapManager.removeRoutingControl(control);
            } catch {
                // Ignore errors on removal
            }
        }
        this._routingControls = [];
    }

    _centerMapOnOrders() {
        const orders = this.unassignedOrders;
        if (!orders.length) return;
        // Use _getOrderStops so multi-drop (waypoints) orders are included
        const allStops = orders.flatMap((o) => this._getOrderStops(o));
        const lats = allStops.map((s) => s.lat).filter(Boolean);
        const lngs = allStops.map((s) => s.lng).filter(Boolean);
        if (!lats.length) return;
        // Mark that we have centered on orders so getUserLocation() cannot override.
        this._mapCenteredOnOrders = true;
        // Use fitBounds so the map zooms to show ALL markers regardless of how
        // geographically spread they are. A raw centroid of stops in Sydney,
        // Singapore and Mongolia would land in Brunei — fitBounds avoids this.
        const minLat = Math.min(...lats);
        const maxLat = Math.max(...lats);
        const minLng = Math.min(...lngs);
        const maxLng = Math.max(...lngs);
        // Update mapCenter to the geographic centre of the bounding box so the
        // LeafletMap @lat/@lng args stay in sync.
        const lat = (minLat + maxLat) / 2;
        const lng = (minLng + maxLng) / 2;
        this.mapCenter = { lat, lng };
        if (this.leafletMap) {
            if (lats.length === 1) {
                // Single point — setView with a sensible zoom level.
                this.leafletMap.setView([lat, lng], 14);
            } else {
                // Multiple points — fit the bounding box with padding.
                this.leafletMap.fitBounds(
                    [
                        [minLat, minLng],
                        [maxLat, maxLng],
                    ],
                    { padding: [40, 40], maxZoom: 14 }
                );
            }
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
            // Build the ordered [[lat, lng], ...] waypoint array for the OSRM
            // routing control. The routing control draws the actual road path.
            const routeWaypoints = this._buildRouteWaypoints(group.orders, group.vehicle);
            return {
                ...group,
                routeColor,
                summary: this.routeSummaries[vehicleId] ?? {},
                routeWaypoints,
            };
        });
    }
    /**
     * Build an ordered [[lat, lng], ...] waypoint array for a vehicle's stop list.
     * Starts at the vehicle/driver's current location (if known), then threads
     * through each stop in sequence order. This array is passed directly to
     * leafletMapManager.addRoutingControl() which calls OSRM to draw the actual
     * road path on the map.
     *
     * @param {Array}  orders  - Sorted stop items { order, sequence, arrival }
     * @param {Object} vehicle - Vehicle record (plain JSON from orchestrator/orders)
     * @returns {Array|null}   - [[lat,lng], ...] or null if fewer than 2 valid points
     */
    _buildRouteWaypoints(orders, vehicle) {
        const points = [];
        // Only include the actual order stop coordinates (pickup + dropoff / waypoints).
        // Do NOT prepend the driver/vehicle current GPS location — that adds an extra
        // OSRM waypoint that is not part of the selected orders and produces incorrect
        // route geometry (e.g. 3 points for a 1-order plan with 2 stops).
        for (const item of orders) {
            const stops = this._getOrderStops(item.order);
            for (const stop of stops) {
                if (stop.lat && stop.lng) {
                    points.push([stop.lat, stop.lng]);
                }
            }
        }
        return points.length >= 2 ? points : null;
    }

    /**
     * Extract { lat, lng } from a place object.
     *
     * The orchestrator/orders endpoint returns places with a GeoJSON Point:
     *   location: { type: 'Point', coordinates: [longitude, latitude] }
     * Note: GeoJSON uses [lng, lat] order, not [lat, lng].
     *
     * Falls back to direct .latitude / .longitude properties for Ember Data records.
     *
     * @param {Object} place
     * @returns {{ lat: number, lng: number } | null}
     */
    _placeCoords(place) {
        if (!place) return null;
        // GeoJSON Point (plain JSON from orchestrator/orders endpoint)
        const loc = place.location;
        if (loc?.type === 'Point' && Array.isArray(loc.coordinates) && loc.coordinates.length >= 2) {
            const lng = parseFloat(loc.coordinates[0]);
            const lat = parseFloat(loc.coordinates[1]);
            if (lat && lng) return { lat, lng };
        }
        // Ember Data model with direct lat/lng attributes
        const lat = parseFloat(place.latitude);
        const lng = parseFloat(place.longitude);
        if (lat && lng) return { lat, lng };
        return null;
    }

    /**
     * Normalise an order's stops into a flat array of { lat, lng, address, label }
     * regardless of whether the order uses pickup/dropoff or a waypoints array.
     *
     * Works with both plain JSON objects (from orchestrator/orders endpoint) and
     * Ember Data model records. Handles GeoJSON Point location format.
     *
     * - Pickup/dropoff orders: returns [pickup, dropoff] (either may be absent)
     * - Multi-drop orders (payload.waypoints): returns each waypoint's place in
     *   sequence order, labelled by stop number (1, 2, 3…)
     *
     * @param {Object} order
     * @returns {Array<{lat: number, lng: number, address: string, label: string}>}
     */
    _getOrderStops(order) {
        const payload = order?.payload;
        if (!payload) return [];

        // Multi-drop: payload has a non-empty waypoints array and no pickup/dropoff.
        // NOTE: isMultiDrop is an Ember Data computed property and won't exist on
        // plain JSON objects returned by the orchestrator/orders endpoint.
        const waypoints = payload.waypoints;
        const hasWaypoints = Array.isArray(waypoints) && waypoints.length > 0;
        const isMultiDrop = payload.isMultiDrop === true || (hasWaypoints && !payload.pickup && !payload.dropoff);
        if (isMultiDrop && hasWaypoints) {
            const sorted = [...waypoints].sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
            return sorted
                .map((wp, idx) => {
                    const place = wp.place ?? wp;
                    const coords = this._placeCoords(place);
                    if (!coords) return null;
                    return { ...coords, address: place.address ?? '', label: String(idx + 1) };
                })
                .filter(Boolean);
        }

        // Standard pickup → dropoff order
        const stops = [];
        const pickup = payload.pickup;
        const dropoff = payload.dropoff;
        const pickupCoords = this._placeCoords(pickup);
        const dropoffCoords = this._placeCoords(dropoff);
        // Address fallbacks: the orchestrator/orders endpoint may return an empty
        // place.address string. Fall back to order-level pickup_name / dropoff_name
        // fields which are always populated from the API resource.
        if (pickupCoords) {
            const addr = pickup?.address || order?.pickup_name || order?.payload?.pickup?.name || '';
            stops.push({ ...pickupCoords, address: addr, label: 'P' });
        }
        if (dropoffCoords) {
            const addr = dropoff?.address || order?.dropoff_name || order?.payload?.dropoff?.name || '';
            stops.push({ ...dropoffCoords, address: addr, label: 'D' });
        }
        return stops;
    }

    /**
     * HBS-callable wrapper around _getOrderStops.
     * Returns a normalised stop array for any order type.
     *
     * @param {Object} order
     * @returns {Array<{lat, lng, address, label}>}
     */
    @action getOrderStops(order) {
        return this._getOrderStops(order);
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
                // vehicle.driver is an async belongsTo proxy — resolve from availableDrivers by driver_id
                const driver = this.availableDrivers.find((d) => d.public_id === assignment.driver_id)
                    ?? this.availableDrivers.find((d) => d.vehicle_id === vehicle_id);
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
        return new Date(unix * 1000).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
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
