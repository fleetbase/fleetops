import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * OrderAllocationWorkbenchComponent
 *
 * The Dispatcher Workbench — the primary UI for the Intelligent Order
 * Allocation Engine. Provides:
 *
 *   - Order Bucket: list of unassigned orders (drag source)
 *   - Vehicle/Driver Bucket: list of available vehicles with driver info
 *   - Proposed Plan View: the allocation result with per-vehicle route lists
 *   - Drag-and-drop override: dispatcher can reassign any order to any vehicle
 *   - Commit / Discard actions
 *
 * The component is rendered at /operations/allocation and is also accessible
 * as an overlay from the live map via the order-list-overlay.
 */
export default class OrderAllocationWorkbenchComponent extends Component {
    @service store;
    @service fetch;
    @service notifications;
    @service intl;
    @service modalsManager;
    @service('order-allocation') allocationService;

    /** Unassigned orders loaded from the store. */
    @tracked unassignedOrders = [];

    /** Available vehicles (with online drivers). */
    @tracked availableVehicles = [];

    /** The proposed allocation plan — null until a run completes. */
    @tracked proposedPlan = null;

    /** Whether the workbench is in "committed" state (plan applied). */
    @tracked isCommitted = false;

    /** Orders that the engine could not assign. */
    @tracked unassignedAfterRun = [];

    /** Tracks manual drag-and-drop overrides made by the dispatcher. */
    @tracked manualOverrides = {};

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    constructor() {
        super(...arguments);
        this.loadData.perform();
    }

    // -------------------------------------------------------------------------
    // Data loading
    // -------------------------------------------------------------------------

    @task *loadData() {
        yield this.loadUnassignedOrders.perform();
        yield this.loadAvailableVehicles.perform();
    }

    @task *loadUnassignedOrders() {
        try {
            const orders = yield this.store.query('order', {
                unassigned: true,
                status:     'created',
                limit:      200,
            });
            this.unassignedOrders = orders.toArray();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *loadAvailableVehicles() {
        try {
            const vehicles = yield this.store.query('vehicle', {
                with_online_driver: true,
                limit: 200,
            });
            this.availableVehicles = vehicles.toArray();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    // -------------------------------------------------------------------------
    // Allocation actions
    // -------------------------------------------------------------------------

    /**
     * Run the allocation engine and populate the proposed plan view.
     */
    @task *runAllocation() {
        this.proposedPlan = null;
        this.isCommitted  = false;
        this.manualOverrides = {};

        const orderIds   = this.unassignedOrders.map((o) => o.public_id);
        const vehicleIds = this.availableVehicles.map((v) => v.public_id);

        try {
            const result = yield this.allocationService.run.perform(orderIds, vehicleIds, {
                balance_workload: false,
            });

            this.proposedPlan       = result.assignments ?? [];
            this.unassignedAfterRun = result.unassigned ?? [];
        } catch (error) {
            // Error notification already handled by the service
        }
    }

    /**
     * Commit the (possibly modified) proposed plan.
     */
    @task *commitPlan() {
        if (!this.proposedPlan?.length) {
            return;
        }

        // Apply any manual overrides before committing
        const finalAssignments = this.proposedPlan.map((assignment) => {
            const override = this.manualOverrides[assignment.order_id];
            return override ? { ...assignment, ...override } : assignment;
        });

        try {
            yield this.allocationService.commit.perform(finalAssignments);
            this.isCommitted  = true;
            this.proposedPlan = null;
            yield this.loadData.perform();
        } catch (error) {
            // Error notification already handled by the service
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
    }

    // -------------------------------------------------------------------------
    // Drag-and-drop override
    // -------------------------------------------------------------------------

    /**
     * Handle a drag-and-drop reassignment.
     * Called when the dispatcher drops an order onto a different vehicle row.
     *
     * @param {string} orderId    The public_id of the dragged order.
     * @param {string} vehicleId  The public_id of the target vehicle.
     * @param {string} driverId   The public_id of the target vehicle's driver.
     */
    @action handleDrop(orderId, vehicleId, driverId) {
        this.manualOverrides = {
            ...this.manualOverrides,
            [orderId]: { vehicle_id: vehicleId, driver_id: driverId },
        };

        // Update the proposedPlan array so the UI reflects the override immediately
        this.proposedPlan = this.proposedPlan.map((assignment) => {
            if (assignment.order_id === orderId) {
                return { ...assignment, vehicle_id: vehicleId, driver_id: driverId, _overridden: true };
            }
            return assignment;
        });
    }

    // -------------------------------------------------------------------------
    // Computed helpers
    // -------------------------------------------------------------------------

    /**
     * Group proposed assignments by vehicle_id for the plan view.
     * Returns an array of { vehicle, driver, orders } objects.
     */
    get planByVehicle() {
        if (!this.proposedPlan?.length) {
            return [];
        }

        const groups = {};
        for (const assignment of this.proposedPlan) {
            if (!groups[assignment.vehicle_id]) {
                const vehicle = this.availableVehicles.find((v) => v.public_id === assignment.vehicle_id);
                groups[assignment.vehicle_id] = {
                    vehicle,
                    driver:  vehicle?.driver,
                    orders:  [],
                };
            }
            const order = this.unassignedOrders.find((o) => o.public_id === assignment.order_id);
            if (order) {
                groups[assignment.vehicle_id].orders.push({
                    order,
                    sequence:     assignment.sequence,
                    _overridden:  assignment._overridden ?? false,
                });
            }
        }

        return Object.values(groups).sort((a, b) => (a.vehicle?.name ?? '').localeCompare(b.vehicle?.name ?? ''));
    }

    get hasProposedPlan() {
        return Array.isArray(this.proposedPlan) && this.proposedPlan.length > 0;
    }

    get hasUnassigned() {
        return this.unassignedAfterRun.length > 0;
    }
}
