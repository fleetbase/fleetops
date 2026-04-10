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
 * Tabs:
 *   - Routes   — per-vehicle route cards with ordered stop list
 *   - Timeline — Gantt-style schedule aligned to the actual day
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

    // ── Timeline helpers ──────────────────────────────────────────────────────

    /**
     * Resolve the best available Unix timestamp (seconds) for a stop item.
     *
     * Priority:
     *   1. VROOM arrival time (item.arrival — Unix seconds)
     *   2. order.scheduled_at (JS Date object from Ember Data)
     *   3. null — caller must use a fallback cursor
     */
    _resolveStopTime(item) {
        if (item.arrival && typeof item.arrival === 'number') {
            return item.arrival;
        }
        const scheduledAt = item.order?.scheduled_at;
        if (scheduledAt instanceof Date && !isNaN(scheduledAt)) {
            return Math.floor(scheduledAt.getTime() / 1000);
        }
        return null;
    }

    /**
     * Build timeline rows for the Gantt view.
     *
     * Each row represents one vehicle and contains a list of time-positioned
     * blocks. Blocks are anchored to the actual scheduled day:
     *
     *   - When VROOM returns arrival times (Unix seconds) those are used directly.
     *   - When orders have scheduled_at dates those are used as the anchor.
     *   - When neither is available we fall back to a 30-minute slot per stop
     *     starting from the beginning of today (not "now"), so the axis still
     *     shows a meaningful date rather than the current wall-clock time.
     */
    get timelineRows() {
        const plan = this.args.planByVehicle ?? [];
        if (!plan.length) return [];

        // Default anchor: start of today (midnight local time)
        const todayMidnight = new Date();
        todayMidnight.setHours(0, 0, 0, 0);
        const todayMidnightSec = Math.floor(todayMidnight.getTime() / 1000);

        const SLOT_SEC = 30 * 60; // 30-minute default slot

        let axisStart = Infinity;
        let axisEnd = -Infinity;

        const rows = plan.map((group) => {
            // Find the earliest real time in this group to use as the cursor start
            let firstRealTime = null;
            for (const item of group.orders ?? []) {
                const t = this._resolveStopTime(item);
                if (t !== null) {
                    firstRealTime = t;
                    break;
                }
            }
            // Cursor starts at the first real time, or today midnight as fallback
            let cursor = firstRealTime ?? todayMidnightSec + 8 * 3600; // 08:00 today

            const blocks = (group.orders ?? []).map((item) => {
                const resolved = this._resolveStopTime(item);
                const start = resolved ?? cursor;
                const end = start + SLOT_SEC;
                cursor = end;

                // Derive display info
                const order = item.order;
                const tracking = order?.tracking ?? order?.public_id ?? '—';
                const customerName = order?.customer_name ?? null;
                const pickupAddress = order?.payload?.pickup?.address ?? order?.pickup_name ?? null;
                const dropoffAddress = order?.payload?.dropoff?.address ?? order?.dropoff_name ?? null;

                // Format times for display
                const startDate = new Date(start * 1000);
                const endDate = new Date(end * 1000);
                const fmt = (d) =>
                    d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
                const fmtFull = (d) =>
                    d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) +
                    ' ' +
                    fmt(d);

                return {
                    item,
                    start,
                    end,
                    tracking,
                    customerName,
                    pickupAddress,
                    dropoffAddress,
                    startLabel: fmt(startDate),
                    endLabel: fmt(endDate),
                    startFull: fmtFull(startDate),
                    endFull: fmtFull(endDate),
                    dateLabel: startDate.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }),
                };
            });

            if (blocks.length) {
                axisStart = Math.min(axisStart, blocks[0].start);
                axisEnd = Math.max(axisEnd, blocks[blocks.length - 1].end);
            }

            return { group, blocks };
        });

        if (axisStart === Infinity) return [];

        // Snap axis start back to the nearest hour boundary for clean alignment
        const snappedStart = Math.floor(axisStart / 3600) * 3600;
        // Pad end by at least one slot
        const snappedEnd = Math.ceil((axisEnd + SLOT_SEC * 0.5) / 3600) * 3600;
        const axisSpan = Math.max(snappedEnd - snappedStart, 3600);

        return rows.map(({ group, blocks }) => ({
            group,
            blocks: blocks.map((b) => ({
                ...b,
                leftPct: (((b.start - snappedStart) / axisSpan) * 100).toFixed(2),
                widthPct: Math.max((((b.end - b.start) / axisSpan) * 100), 1.5).toFixed(2),
            })),
            axisStart: snappedStart,
            axisSpan,
        }));
    }

    /**
     * Hour tick marks for the timeline axis.
     * Returns an array of { label, dateLabel, leftPct, isDateBoundary } for each
     * hour boundary visible. Date boundaries (midnight) get a date label.
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
            const hour = d.getHours();
            const isDateBoundary = hour === 0;
            const timeLabel = d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
            const dateLabel = isDateBoundary
                ? d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })
                : null;
            ticks.push({ label: timeLabel, dateLabel, leftPct, isDateBoundary });
        }
        return ticks;
    }

    /**
     * The date range covered by the timeline, formatted for the header.
     * e.g. "Thu Apr 10, 2026" or "Thu Apr 10 – Fri Apr 11, 2026"
     */
    get timelineDateRange() {
        const rows = this.timelineRows;
        if (!rows.length) return null;
        const { axisStart, axisSpan } = rows[0];
        const startDate = new Date(axisStart * 1000);
        const endDate = new Date((axisStart + axisSpan) * 1000);
        const opts = { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' };
        const startStr = startDate.toLocaleDateString('en-US', opts);
        const endDay = endDate.toDateString();
        const startDay = startDate.toDateString();
        if (startDay === endDay) return startStr;
        return `${startDate.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })} – ${endDate.toLocaleDateString('en-US', opts)}`;
    }
}
