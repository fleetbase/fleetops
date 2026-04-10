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
    @tracked activeTab = 'routes'; // 'routes' | 'timeline'

    @action setTab(tab) {
        this.activeTab = tab;
    }

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

    /**
     * isExpanded — decorated as @action so Glimmer allows it to be invoked
     * with an argument from HBS: (this.isExpanded vehicleId)
     */
    @action isExpanded(vehicleId) {
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

    // ── Timeline helpers ──────────────────────────────────────────────────────────────────────────────

    /**
     * Build timeline rows for the Gantt view.
     * Each row represents one vehicle and contains a list of time-positioned blocks.
     *
     * When VROOM returns arrival times (Unix seconds) we use those directly.
     * For greedy-engine results (no arrival times) we fall back to a fixed
     * 30-minute slot per stop starting from now so the timeline is still useful.
     */
    get timelineRows() {
        const plan = this.args.planByVehicle ?? [];
        if (!plan.length) return [];

        const nowSec = Math.floor(Date.now() / 1000);
        const SLOT_SEC = 30 * 60; // 30-minute default slot

        // Determine the global time window for the axis
        let axisStart = Infinity;
        let axisEnd = -Infinity;

        const rows = plan.map((group) => {
            let cursor = nowSec;
            const blocks = (group.orders ?? []).map((item) => {
                const start = item.arrival ?? cursor;
                const end = start + SLOT_SEC;
                cursor = end;
                return { item, start, end };
            });

            if (blocks.length) {
                axisStart = Math.min(axisStart, blocks[0].start);
                axisEnd = Math.max(axisEnd, blocks[blocks.length - 1].end);
            }

            return { group, blocks };
        });

        if (axisStart === Infinity) return [];

        // Pad the axis slightly
        const span = Math.max(axisEnd - axisStart, SLOT_SEC);
        const paddedStart = axisStart - SLOT_SEC * 0.25;
        const paddedSpan = span + SLOT_SEC * 0.5;

        // Attach percentage offsets to each block
        return rows.map(({ group, blocks }) => ({
            group,
            blocks: blocks.map((b) => ({
                ...b,
                leftPct: (((b.start - paddedStart) / paddedSpan) * 100).toFixed(2),
                widthPct: Math.max((((b.end - b.start) / paddedSpan) * 100), 2).toFixed(2),
            })),
            axisStart: paddedStart,
            axisSpan: paddedSpan,
        }));
    }

    /**
     * Hour tick marks for the timeline axis.
     * Returns an array of { label, leftPct } for each hour boundary visible.
     */
    get timelineAxisTicks() {
        const rows = this.timelineRows;
        if (!rows.length) return [];
        const { axisStart, axisSpan } = rows[0];
        const ticks = [];
        const firstHour = Math.ceil(axisStart / 3600) * 3600;
        for (let t = firstHour; t < axisStart + axisSpan; t += 3600) {
            const leftPct = (((t - axisStart) / axisSpan) * 100).toFixed(2);
            const d = new Date(t * 1000);
            const label = d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
            ticks.push({ label, leftPct });
        }
        return ticks;
    }
}
