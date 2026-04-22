<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1\Traits;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DriverSchedulingTrait.
 *
 * Provides scheduling-related controller methods for the DriverController.
 * These methods expose the core-api scheduling relationships (ScheduleItem,
 * ScheduleAvailability) through FleetOps-specific driver endpoints.
 *
 * Routes:
 *   GET /int/v1/drivers/{id}/schedule-items
 *   GET /int/v1/drivers/{id}/availabilities
 *   GET /int/v1/drivers/{id}/hos-status
 *   GET /int/v1/drivers/{id}/active-shift
 *
 * The {id} parameter accepts either the driver's UUID or public_id.
 */
trait DriverSchedulingTrait
{
    /**
     * Regulatory HOS defaults used when no per-schedule override is configured.
     * These match the US FMCSA 11-hour / 70-hour rules as a sensible baseline.
     */
    private const HOS_DEFAULT_DAILY_LIMIT  = 11;
    private const HOS_DEFAULT_WEEKLY_LIMIT = 70;

    /**
     * Resolve a Driver by UUID or public_id, throwing 404 if not found.
     */
    private function resolveDriver(string $id): Driver
    {
        return Driver::where('uuid', $id)
            ->orWhere('public_id', $id)
            ->firstOrFail();
    }

    /**
     * Return all ScheduleItem records assigned to this driver.
     * Supports optional date range filtering via start_at and end_at query params.
     *
     * GET /int/v1/drivers/{id}/schedule-items
     */
    public function scheduleItems(string $id, Request $request): JsonResponse
    {
        $driver = $this->resolveDriver($id);
        $query  = $driver->scheduleItems();

        if ($request->filled('start_at')) {
            $query->where('start_at', '>=', $request->input('start_at'));
        }
        if ($request->filled('end_at')) {
            $query->where('end_at', '<=', $request->input('end_at'));
        }

        return response()->json(['data' => $query->orderBy('start_at')->get()]);
    }

    /**
     * Return all ScheduleAvailability records for this driver.
     * Supports optional date range filtering via start_at and end_at query params.
     *
     * GET /int/v1/drivers/{id}/availabilities
     */
    public function availabilities(string $id, Request $request): JsonResponse
    {
        $driver = $this->resolveDriver($id);
        $query  = $driver->availabilities();

        if ($request->filled('start_at')) {
            $query->where('start_at', '>=', $request->input('start_at'));
        }
        if ($request->filled('end_at')) {
            $query->where('end_at', '<=', $request->input('end_at'));
        }

        return response()->json(['data' => $query->orderBy('start_at')->get()]);
    }

    /**
     * Return the driver's Hours of Service (HOS) status.
     *
     * ## Data source
     *
     * The `hos_source` field on the driver's active Schedule determines how
     * driving hours are accumulated:
     *
     *  - `schedule` (default): Hours are derived from the driver's ScheduleItem
     *    records. Any shift whose `start_at` is in the past (i.e. has already
     *    started) is counted. For ongoing shifts (no `end_at` or end_at > NOW()),
     *    elapsed time up to NOW() is used. This gives a meaningful "hours
     *    scheduled/worked so far" reading immediately, without requiring
     *    clock-in/clock-out or telematics data.
     *
     *  - `telematics` (future): Accumulated from GPS movement data.
     *  - `manual` (future): Manually logged by a dispatcher.
     *
     * ## Limits
     *
     * Limits are resolved in priority order:
     *   1. Per-schedule `hos_daily_limit` / `hos_weekly_limit` (if set on the Schedule)
     *   2. Hard-coded regulatory defaults: 11h daily / 70h weekly (US FMCSA baseline)
     *
     * GET /int/v1/drivers/{id}/hos-status
     */
    public function hosStatus(string $id): JsonResponse
    {
        $driver = $this->resolveDriver($id);

        // ── Resolve the driver's active schedule ──────────────────────────────
        /** @var Schedule|null $schedule */
        $schedule = $driver->schedules()
            ->where('status', 'active')
            ->latest('created_at')
            ->first();

        // ── Determine HOS limits (per-schedule → global default) ──────────────
        $dailyLimit  = ($schedule && $schedule->hos_daily_limit)
            ? (int) $schedule->hos_daily_limit
            : self::HOS_DEFAULT_DAILY_LIMIT;

        $weeklyLimit = ($schedule && $schedule->hos_weekly_limit)
            ? (int) $schedule->hos_weekly_limit
            : self::HOS_DEFAULT_WEEKLY_LIMIT;

        // ── Determine HOS source ──────────────────────────────────────────────
        $hosSource = $schedule ? ($schedule->hos_source ?? 'schedule') : 'schedule';

        // ── Calculate hours based on source ───────────────────────────────────
        [$dailyHours, $weeklyHours] = match ($hosSource) {
            // Future sources (telematics, manual) will be added here
            default => $this->calculateHosFromSchedule($driver),
        };

        return response()->json([
            'daily_hours'   => $dailyHours,
            'weekly_hours'  => $weeklyHours,
            'daily_limit'   => $dailyLimit,
            'weekly_limit'  => $weeklyLimit,
            'hos_source'    => $hosSource,
            'is_compliant'  => $dailyHours < $dailyLimit && $weeklyHours < $weeklyLimit,
        ]);
    }

    /**
     * Calculate HOS hours from the driver's ScheduleItem records.
     *
     * Counts all shifts whose `start_at` is in the past (i.e. the shift has
     * already started). For ongoing shifts (end_at is null or in the future),
     * elapsed time up to NOW() is used via LEAST(COALESCE(end_at, NOW()), NOW()).
     *
     * Cancelled shifts are excluded. All other statuses (scheduled, in_progress,
     * completed) are included — a shift that has started counts toward HOS
     * regardless of whether it has been formally marked completed.
     *
     * @return array{float, float} [dailyHours, weeklyHours]
     */
    private function calculateHosFromSchedule(Driver $driver): array
    {
        $now         = now();
        $startOfDay  = $now->copy()->startOfDay();
        $startOfWeek = $now->copy()->startOfWeek();

        // Duration expression: elapsed minutes from shift start to MIN(end_at, NOW()).
        // This caps ongoing shifts at the current moment so future time is never counted.
        $durationExpr = DB::raw(
            'TIMESTAMPDIFF(MINUTE, start_at, LEAST(COALESCE(end_at, NOW()), NOW()))'
        );

        // Daily: shifts that started today and have already begun
        $dailyMinutes = $driver->scheduleItems()
            ->where('start_at', '>=', $startOfDay)
            ->where('start_at', '<=', $now)
            ->where('status', '!=', 'cancelled')
            ->sum($durationExpr);

        // Weekly: shifts that started this week and have already begun
        $weeklyMinutes = $driver->scheduleItems()
            ->where('start_at', '>=', $startOfWeek)
            ->where('start_at', '<=', $now)
            ->where('status', '!=', 'cancelled')
            ->sum($durationExpr);

        return [
            round((float) $dailyMinutes / 60, 1),
            round((float) $weeklyMinutes / 60, 1),
        ];
    }

    /**
     * Return the driver's currently active shift for today.
     * Used by OrchestrationPayloadBuilder to inject time_window constraints.
     *
     * GET /int/v1/drivers/{id}/active-shift
     */
    public function activeShift(string $id): JsonResponse
    {
        $driver = $this->resolveDriver($id);
        $shift  = $driver->activeShiftFor(now());

        if (!$shift) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $shift]);
    }
}
