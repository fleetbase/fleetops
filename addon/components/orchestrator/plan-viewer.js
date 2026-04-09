import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * Orchestrator::PlanViewer
 *
 * Displays the proposed plan after a run completes. Shows per-vehicle
 * route cards with ordered stop rows. Supports drag-and-drop manual
 * overrides and expand/collapse per route card.
 *
 * @arg planByVehicle         - Array of { vehicle, driver, orders, routeColor, summary }
 * @arg unassignedAfterRun    - Array of orders the engine could not assign
 * @arg runMessage            - Optional informational message from the engine
 * @arg onDropOnVehicle       - Action(vehicleId, driverId, event)
 * @arg onDragOver            - Action(event)
 * @arg onDismissMessage      - Action — dismiss the run message banner
 * @arg formatDuration        - Helper(seconds) → string
 * @arg formatDistance        - Helper(metres) → string
 * @arg formatUnixTime        - Helper(unix) → string
 */
export default class OrchestratorPlanViewerComponent extends Component {
    @tracked expandedCards = new Set();

    @action toggleCard(vehicleId) {
        const expanded = new Set(this.expandedCards);
        if (expanded.has(vehicleId)) {
            expanded.delete(vehicleId);
        } else {
            expanded.add(vehicleId);
        }
        this.expandedCards = expanded;
    }

    @action expandAll() {
        const ids = (this.args.planByVehicle ?? []).map((g) => g.vehicle?.public_id).filter(Boolean);
        this.expandedCards = new Set(ids);
    }

    @action collapseAll() {
        this.expandedCards = new Set();
    }

    isExpanded(vehicleId) {
        return this.expandedCards.has(vehicleId);
    }

    get hasUnassigned() {
        return (this.args.unassignedAfterRun ?? []).length > 0;
    }

    get totalOrders() {
        return (this.args.planByVehicle ?? []).reduce((sum, g) => sum + (g.orders?.length ?? 0), 0);
    }

    get totalVehicles() {
        return (this.args.planByVehicle ?? []).length;
    }
}
