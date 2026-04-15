import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { addMinutes } from 'date-fns';
import toCalendarDate from '../../utils/to-calendar-date';

/**
 * Orchestrator::PlanViewer
 *
 * Displays the proposed plan after a run completes. Shows per-vehicle
 * route cards with ordered stop rows. Supports drag-and-drop manual
 * overrides and expand/collapse per route card.
 *
 * Tabs:
 *   - Routes   — per-vehicle route cards with ordered stop list
 *   - Timeline — EventCalendar resourceTimelineDay view (same library as Scheduler)
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
    @service currentUser;

    @tracked expandedCards = new Set();
    @tracked activeTab = 'routes'; // 'routes' | 'timeline'
    @tracked _calendar = null;

    // ── Tab / card actions ────────────────────────────────────────────────────

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
     * Returns true when every vehicle card is expanded.
     * Used to show the correct label on the single toggle button.
     */
    get allExpanded() {
        const ids = (this.args.planByVehicle ?? []).map((g) => g.vehicle?.public_id).filter(Boolean);
        return ids.length > 0 && ids.every((id) => this.expandedCards.has(id));
    }

    @action toggleExpandAll() {
        if (this.allExpanded) {
            this.collapseAll();
        } else {
            this.expandAll();
        }
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

    // ── EventCalendar ─────────────────────────────────────────────────────────

    /**
     * Returns the IANA timezone string for the current organisation.
     * Falls back to the browser's local timezone.
     */
    get companyTimezone() {
        return this.currentUser?.company?.timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone;
    }

    /**
     * Receives the EventCalendar instance once it is mounted.
     */
    @action setCalendarApi(calendar) {
        this._calendar = calendar;
    }

    /**
     * The date to display on the timeline — derived from the earliest scheduled
     * order in the plan, so the calendar opens on the right day automatically.
     */
    get timelineDate() {
        const plan = this.args.planByVehicle ?? [];
        let earliest = null;
        for (const group of plan) {
            for (const item of group.orders ?? []) {
                const t = this._resolveStopTime(item);
                if (t !== null) {
                    if (earliest === null || t < earliest) earliest = t;
                }
            }
        }
        if (earliest === null) return new Date();
        // Convert the UTC unix timestamp to a wall-clock Date in the company timezone.
        return toCalendarDate(new Date(earliest * 1000), this.companyTimezone);
    }

    /**
     * Resources for EventCalendar — one row per vehicle/driver group.
     * When a driver phase ran (@hasDriverPhase), the row title uses the driver
     * name as the primary identifier; otherwise the vehicle name is used.
     */
    get calendarResources() {
        const hasDriverPhase = this.args.hasDriverPhase ?? false;
        return (this.args.planByVehicle ?? []).map((group) => {
            const primaryTitle =
                hasDriverPhase && group.driver ? (group.driver.name ?? group.driver.display_name ?? 'Driver') : (group.vehicle?.display_name ?? group.vehicle?.name ?? 'Vehicle');
            return {
                id: group.vehicle?.public_id ?? group.vehicle?.id ?? String(Math.random()),
                title: primaryTitle,
                extendedProps: { group, hasDriverPhase },
            };
        });
    }

    /**
     * Events for EventCalendar — one event per planned stop.
     * Uses the vehicle's routeColor for consistent color coding.
     */
    get calendarEvents() {
        const tz = this.companyTimezone;
        const events = [];
        const DURATION_MIN = 30; // default slot duration when no estimate available

        for (const group of this.args.planByVehicle ?? []) {
            const resourceId = group.vehicle?.public_id ?? group.vehicle?.id;
            const color = group.routeColor ?? '#6366f1';

            // Cursor used when no arrival time is known — starts at 08:00 on the plan date
            let cursorMs = null;

            for (const item of group.orders ?? []) {
                const stopTimeSec = this._resolveStopTime(item);
                let startMs;

                if (stopTimeSec !== null) {
                    startMs = stopTimeSec * 1000;
                    cursorMs = startMs + DURATION_MIN * 60 * 1000;
                } else {
                    // No time known — use cursor or default to 08:00 today
                    if (cursorMs === null) {
                        const today = new Date();
                        today.setHours(8, 0, 0, 0);
                        cursorMs = today.getTime();
                    }
                    startMs = cursorMs;
                    cursorMs = startMs + DURATION_MIN * 60 * 1000;
                }

                const startUtc = new Date(startMs);
                const endUtc = addMinutes(startUtc, DURATION_MIN);

                // Convert to "fake local" dates for EventCalendar timezone handling
                const start = toCalendarDate(startUtc, tz);
                const end = toCalendarDate(endUtc, tz);

                const order = item.order;
                const tracking = order?.tracking ?? order?.public_id ?? '—';
                const customerName = order?.customer_name ?? null;
                const pickupAddress = order?.payload?.pickup?.address ?? order?.pickup_name ?? null;
                const dropoffAddress = order?.payload?.dropoff?.address ?? order?.dropoff_name ?? null;
                const sequence = item.sequence ?? null;

                events.push({
                    id: `plan-${resourceId}-${order?.public_id ?? Math.random()}`,
                    resourceId,
                    title: tracking,
                    start,
                    end,
                    display: 'block',
                    backgroundColor: color,
                    borderColor: color,
                    textColor: '#ffffff',
                    extendedProps: {
                        order,
                        tracking,
                        sequence,
                        customerName,
                        pickupAddress,
                        dropoffAddress,
                        startUtc,
                        endUtc,
                    },
                });
            }
        }

        return events;
    }

    /**
     * Renders the resource label cell for each timeline row.
     * When a driver phase ran (hasDriverPhase), the driver name is the primary
     * label and the vehicle name is the secondary sub-label. Otherwise the
     * vehicle name is primary and the driver name (if any) is secondary.
     */
    @action renderResourceLabel({ resource }) {
        const { group, hasDriverPhase } = resource.extendedProps ?? {};
        const vehicleName = group?.vehicle?.display_name ?? group?.vehicle?.name ?? 'Vehicle';
        const driverName = group?.driver?.name ?? '';
        const stopCount = group?.orders?.length ?? 0;
        const color = group?.routeColor ?? '#6366f1';

        const primaryLabel = hasDriverPhase && driverName ? driverName : vehicleName;
        const secondaryLabel = hasDriverPhase && driverName ? vehicleName : driverName;

        return {
            html: `<div style="width:100%;box-sizing:border-box;padding:4px 8px;border-left:3px solid ${color};">
                <div style="font-size:0.72rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;width:100%;">${primaryLabel}</div>
                ${secondaryLabel ? `<div style="font-size:0.65rem;color:#9ca3af;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${secondaryLabel}</div>` : ''}
                <div style="font-size:0.62rem;color:#6b7280;margin-top:1px;">${stopCount} stop${stopCount !== 1 ? 's' : ''}</div>
            </div>`,
        };
    }

    /**
     * Renders the event tile content inside the timeline.
     * Shows: sequence + tracking number, scheduled time, pickup → dropoff address, customer.
     */
    @action renderEventContent({ event }) {
        const { tracking, sequence, customerName, pickupAddress, dropoffAddress, startUtc, endUtc } = event.extendedProps ?? {};

        const fmt = (d) => {
            if (!d) return '';
            return d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
        };

        const title = sequence != null ? `${sequence}. ${tracking}` : tracking;
        const timeRange = startUtc ? `${fmt(startUtc)} – ${fmt(endUtc)}` : '';
        const routeLine = [pickupAddress, dropoffAddress].filter(Boolean).join(' > ');

        return {
            html: `<div style="display:flex;flex-direction:column;gap:2px;padding:3px 0;overflow:hidden;height:100%;">
                <div style="display:flex;align-items:center;gap:4px;flex-wrap:nowrap;">
                    <span style="width:7px;height:7px;border-radius:50%;background:#ffffff;opacity:0.9;flex-shrink:0;"></span>
                    <span style="font-size:0.72rem;font-weight:700;color:#ffffff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0;">${title}</span>
                </div>
                ${timeRange ? `<div style="font-size:0.65rem;color:rgba(255,255,255,0.9);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;line-height:1.3;">${timeRange}</div>` : ''}
                ${
                    routeLine
                        ? `<div style="font-size:0.62rem;color:rgba(255,255,255,0.8);overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.3;word-break:break-word;">${routeLine}</div>`
                        : ''
                }
                ${
                    customerName
                        ? `<div style="font-size:0.62rem;color:rgba(255,255,255,0.7);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;line-height:1.3;">${customerName}</div>`
                        : ''
                }
            </div>`,
        };
    }

    /**
     * Calendar display options — 24h time format, no header toolbar
     * (navigation is not needed for a read-only plan review).
     */
    get calendarOptions() {
        return {
            slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            dayHeaderFormat: { weekday: 'short', month: 'numeric', day: 'numeric' },
        };
    }

    get calendarHeaderToolbar() {
        return { start: '', center: 'title', end: '' };
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Resolve the best available Unix timestamp (seconds) for a stop item.
     *
     * Priority:
     *   1. VROOM arrival time (item.arrival — Unix seconds)
     *   2. order.scheduled_at (JS Date object from Ember Data or ISO string from plain JSON)
     *   3. null — caller must use a fallback cursor
     */
    _resolveStopTime(item) {
        // 1. VROOM arrival time (Unix seconds integer)
        if (item.arrival && typeof item.arrival === 'number') {
            return item.arrival;
        }
        // 2. order.scheduled_at — may be a Date object (Ember Data) or an ISO
        //    string (plain JSON from orchestrator/orders endpoint)
        const scheduledAt = item.order?.scheduled_at;
        if (scheduledAt) {
            const d = scheduledAt instanceof Date ? scheduledAt : new Date(scheduledAt);
            if (!isNaN(d.getTime())) {
                return Math.floor(d.getTime() / 1000);
            }
        }
        return null;
    }

    /**
     * The date range covered by the plan, formatted for the header.
     * e.g. "Thu Apr 10, 2026"
     */
    get timelineDateRange() {
        const date = this.timelineDate;
        if (!date) return null;
        return date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
    }
}
