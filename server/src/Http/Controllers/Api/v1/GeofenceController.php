<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Models\GeofenceEventLog;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GeofenceController
 *
 * Exposes read-only API endpoints for querying geofence event data:
 *   - Event log (paginated, filterable history of all geofence events)
 *   - Real-time inventory (which drivers are currently inside which geofences)
 *   - Dwell report (aggregated dwell time statistics per geofence)
 */
class GeofenceController extends Controller
{
    /**
     * GET /api/v1/geofences/events
     *
     * Returns a paginated log of geofence events for the authenticated company.
     *
     * Query parameters:
     *   - driver_uuid   (string)  Filter by driver UUID
     *   - geofence_uuid (string)  Filter by geofence UUID
     *   - event_type    (string)  Filter by event type: entered | exited | dwelled
     *   - from          (string)  Filter events after this ISO 8601 datetime
     *   - to            (string)  Filter events before this ISO 8601 datetime
     *   - per_page      (int)     Number of results per page (default: 50, max: 200)
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function events(Request $request): JsonResponse
    {
        $companyUuid = session('company');

        $query = GeofenceEventLog::where('company_uuid', $companyUuid)
            ->orderBy('occurred_at', 'desc');

        if ($request->filled('driver_uuid')) {
            $query->where('driver_uuid', $request->input('driver_uuid'));
        }

        if ($request->filled('geofence_uuid')) {
            $query->where('geofence_uuid', $request->input('geofence_uuid'));
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->input('event_type'));
        }

        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('occurred_at', '<=', $request->input('to'));
        }

        $perPage = min((int) $request->input('per_page', 50), 200);

        return response()->json($query->paginate($perPage));
    }

    /**
     * GET /api/v1/geofences/inventory
     *
     * Returns a real-time snapshot of which drivers are currently inside
     * which geofences for the authenticated company.
     *
     * @return JsonResponse
     */
    public function inventory(): JsonResponse
    {
        $companyUuid = session('company');

        $states = DB::table('driver_geofence_states as dgs')
            ->join('drivers as d', 'd.uuid', '=', 'dgs.driver_uuid')
            ->leftJoin('zones as z', function ($join) {
                $join->on('z.uuid', '=', 'dgs.geofence_uuid')
                     ->where('dgs.geofence_type', '=', 'zone');
            })
            ->leftJoin('service_areas as sa', function ($join) {
                $join->on('sa.uuid', '=', 'dgs.geofence_uuid')
                     ->where('dgs.geofence_type', '=', 'service_area');
            })
            ->where('d.company_uuid', $companyUuid)
            ->where('dgs.is_inside', true)
            ->whereNull('d.deleted_at')
            ->select([
                'dgs.driver_uuid',
                'd.name as driver_name',
                'dgs.geofence_uuid',
                DB::raw('COALESCE(z.name, sa.name) as geofence_name'),
                'dgs.geofence_type',
                'dgs.entered_at',
                DB::raw('TIMESTAMPDIFF(MINUTE, dgs.entered_at, NOW()) as minutes_inside'),
            ])
            ->orderBy('dgs.entered_at', 'asc')
            ->get();

        return response()->json([
            'data'  => $states,
            'total' => $states->count(),
        ]);
    }

    /**
     * GET /api/v1/geofences/dwell-report
     *
     * Returns aggregated dwell time statistics per geofence for the
     * authenticated company.
     *
     * Query parameters:
     *   - from (string) Start of reporting period (ISO 8601)
     *   - to   (string) End of reporting period (ISO 8601)
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function dwellReport(Request $request): JsonResponse
    {
        $companyUuid = session('company');

        $query = GeofenceEventLog::where('company_uuid', $companyUuid)
            ->where('event_type', 'exited')
            ->whereNotNull('dwell_duration_minutes');

        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('occurred_at', '<=', $request->input('to'));
        }

        $report = $query
            ->groupBy('geofence_uuid', 'geofence_name', 'geofence_type')
            ->select([
                'geofence_uuid',
                'geofence_name',
                'geofence_type',
                DB::raw('COUNT(*) as visit_count'),
                DB::raw('ROUND(AVG(dwell_duration_minutes), 1) as avg_dwell_minutes'),
                DB::raw('MAX(dwell_duration_minutes) as max_dwell_minutes'),
                DB::raw('MIN(dwell_duration_minutes) as min_dwell_minutes'),
                DB::raw('SUM(dwell_duration_minutes) as total_dwell_minutes'),
            ])
            ->orderBy('visit_count', 'desc')
            ->get();

        return response()->json(['data' => $report]);
    }

    /**
     * GET /api/v1/geofences/driver/{driverUuid}/history
     *
     * Returns the geofence event history for a specific driver.
     *
     * @param Request $request
     * @param string  $driverUuid
     *
     * @return JsonResponse
     */
    public function driverHistory(Request $request, string $driverUuid): JsonResponse
    {
        $companyUuid = session('company');
        $perPage     = min((int) $request->input('per_page', 50), 200);

        $events = GeofenceEventLog::where('company_uuid', $companyUuid)
            ->where('driver_uuid', $driverUuid)
            ->orderBy('occurred_at', 'desc')
            ->paginate($perPage);

        return response()->json($events);
    }
}
