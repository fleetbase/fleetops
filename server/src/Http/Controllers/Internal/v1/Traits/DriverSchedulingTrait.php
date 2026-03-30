<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1\Traits;

use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DriverSchedulingTrait
 *
 * Provides scheduling-related controller methods for the DriverController.
 * These methods expose the core-api scheduling relationships (ScheduleItem,
 * ScheduleAvailability) through FleetOps-specific driver endpoints.
 *
 * Routes:
 *   GET /int/v1/fleet-ops/drivers/{id}/schedule-items
 *   GET /int/v1/fleet-ops/drivers/{id}/availabilities
 *   GET /int/v1/fleet-ops/drivers/{id}/hos-status
 *   GET /int/v1/fleet-ops/drivers/{id}/active-shift
 */
trait DriverSchedulingTrait
{
    /**
     * Return all ScheduleItem records assigned to this driver.
     * Supports optional date range filtering via start_at and end_at query params.
     *
     * GET /int/v1/fleet-ops/drivers/{id}/schedule-items
     */
    public function scheduleItems(string $id, Request $request): JsonResponse
    {
        $driver = Driver::where('public_id', $id)->firstOrFail();

        $query = $driver->scheduleItems();

        if ($request->filled('start_at')) {
            $query->where('start_at', '>=', $request->input('start_at'));
        }

        if ($request->filled('end_at')) {
            $query->where('end_at', '<=', $request->input('end_at'));
        }

        $items = $query->orderBy('start_at')->get();

        return response()->json([
            'data' => $items,
        ]);
    }

    /**
     * Return all ScheduleAvailability records for this driver.
     * Supports optional date range filtering via start_at and end_at query params.
     *
     * GET /int/v1/fleet-ops/drivers/{id}/availabilities
     */
    public function availabilities(string $id, Request $request): JsonResponse
    {
        $driver = Driver::where('public_id', $id)->firstOrFail();

        $query = $driver->availabilities();

        if ($request->filled('start_at')) {
            $query->where('start_at', '>=', $request->input('start_at'));
        }

        if ($request->filled('end_at')) {
            $query->where('end_at', '<=', $request->input('end_at'));
        }

        $availabilities = $query->orderBy('start_at')->get();

        return response()->json([
            'data' => $availabilities,
        ]);
    }

    /**
     * Return the driver's Hours of Service (HOS) status.
     *
     * Calculates daily and weekly driving hours from completed ScheduleItem
     * records. This is a computed endpoint — it does not persist HOS data.
     * A future iteration may integrate with a dedicated HOS tracking system.
     *
     * GET /int/v1/fleet-ops/drivers/{id}/hos-status
     */
    public function hosStatus(string $id): JsonResponse
    {
        $driver = Driver::where('public_id', $id)->firstOrFail();

        // Daily hours: sum of completed/in-progress shift durations today
        $dailyMinutes = $driver->scheduleItems()
            ->whereDate('start_at', today())
            ->whereIn('status', ['completed', 'in_progress'])
            ->sum(\Illuminate\Support\Facades\DB::raw('TIMESTAMPDIFF(MINUTE, start_at, COALESCE(end_at, NOW()))'));

        // Weekly hours: sum of completed/in-progress shift durations this week
        $weeklyMinutes = $driver->scheduleItems()
            ->whereBetween('start_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->whereIn('status', ['completed', 'in_progress'])
            ->sum(\Illuminate\Support\Facades\DB::raw('TIMESTAMPDIFF(MINUTE, start_at, COALESCE(end_at, NOW()))'));

        $dailyHours  = round($dailyMinutes / 60, 1);
        $weeklyHours = round($weeklyMinutes / 60, 1);

        return response()->json([
            'daily_hours'   => $dailyHours,
            'weekly_hours'  => $weeklyHours,
            'daily_limit'   => 11,
            'weekly_limit'  => 70,
            'is_compliant'  => $dailyHours < 11 && $weeklyHours < 70,
        ]);
    }

    /**
     * Return the driver's currently active shift for today.
     * Used by the AllocationPayloadBuilder to inject time_window constraints.
     *
     * GET /int/v1/fleet-ops/drivers/{id}/active-shift
     */
    public function activeShift(string $id): JsonResponse
    {
        $driver = Driver::where('public_id', $id)->firstOrFail();

        $shift = $driver->activeShiftFor(now());

        if (!$shift) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $shift]);
    }
}
