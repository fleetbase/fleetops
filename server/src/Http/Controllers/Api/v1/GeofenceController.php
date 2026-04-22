<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Models\GeofenceEventLog;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GeofenceController.
 *
 * Exposes read-only API endpoints for querying geofence event data:
 *   - Event log (paginated, filterable history of all geofence events)
 *   - Real-time inventory (which drivers are currently inside which geofences)
 *   - Dwell report (aggregated dwell time statistics per geofence)
 */
class GeofenceController extends Controller
{
    /**
     * GET /api/v1/geofences/events.
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
     */
    public function events(Request $request): JsonResponse
    {
        $companyUuid = session('company');

        $query = GeofenceEventLog::where('company_uuid', $companyUuid)
            ->with(['driver.vehicle', 'vehicle', 'order'])
            ->orderBy('occurred_at', 'desc');

        if ($request->filled('driver_uuid')) {
            $query->where('driver_uuid', $request->input('driver_uuid'));
        }

        if ($request->filled('geofence_uuid')) {
            $query->where('geofence_uuid', $request->input('geofence_uuid'));
        }

        if ($request->filled('vehicle_uuid')) {
            $query->where('vehicle_uuid', $request->input('vehicle_uuid'));
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->input('subject_type'));
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', str_replace('geofence.', '', $request->input('event_type')));
        }

        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('occurred_at', '<=', $request->input('to'));
        }

        $perPage = min((int) $request->input('per_page', 50), 200);

        $events = $query->paginate($perPage);
        $events->getCollection()->transform(fn (GeofenceEventLog $event) => $this->serializeEvent($event));

        return response()->json($events);
    }

    /**
     * GET /api/v1/geofences/inventory.
     *
     * Returns a real-time snapshot of which drivers are currently inside
     * which geofences for the authenticated company.
     */
    public function inventory(): JsonResponse
    {
        $companyUuid = session('company');

        $driverStates = DB::table('driver_geofence_states as dgs')
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
                DB::raw("'driver' as subject_type"),
                'd.public_id as subject_id',
                'd.uuid as subject_uuid',
                'd.name as subject_name',
                'dgs.driver_uuid',
                'd.name as driver_name',
                'dgs.entered_at',
                'dgs.geofence_uuid',
                DB::raw('COALESCE(z.name, sa.name) as geofence_name'),
                'dgs.geofence_type',
                DB::raw('TIMESTAMPDIFF(MINUTE, dgs.entered_at, NOW()) as minutes_inside'),
            ])
            ->get();

        $vehicleStates = DB::table('vehicle_geofence_states as vgs')
            ->join('vehicles as v', 'v.uuid', '=', 'vgs.vehicle_uuid')
            ->leftJoin('zones as z', function ($join) {
                $join->on('z.uuid', '=', 'vgs.geofence_uuid')
                    ->where('vgs.geofence_type', '=', 'zone');
            })
            ->leftJoin('service_areas as sa', function ($join) {
                $join->on('sa.uuid', '=', 'vgs.geofence_uuid')
                    ->where('vgs.geofence_type', '=', 'service_area');
            })
            ->where('v.company_uuid', $companyUuid)
            ->where('vgs.is_inside', true)
            ->whereNull('v.deleted_at')
            ->select([
                DB::raw("'vehicle' as subject_type"),
                'v.public_id as subject_id',
                'v.uuid as subject_uuid',
                DB::raw('COALESCE(v.name, v.plate_number, v.public_id) as subject_name'),
                DB::raw('NULL as driver_uuid'),
                DB::raw('NULL as driver_name'),
                'vgs.entered_at',
                'vgs.geofence_uuid',
                DB::raw('COALESCE(z.name, sa.name) as geofence_name'),
                'vgs.geofence_type',
                DB::raw('TIMESTAMPDIFF(MINUTE, vgs.entered_at, NOW()) as minutes_inside'),
            ])
            ->get();

        $states = $driverStates->merge($vehicleStates)->sortBy('entered_at')->values();

        return response()->json([
            'data'  => $states,
            'total' => $states->count(),
        ]);
    }

    /**
     * GET /api/v1/geofences/dwell-report.
     *
     * Returns aggregated dwell time statistics per geofence for the
     * authenticated company.
     *
     * Query parameters:
     *   - from (string) Start of reporting period (ISO 8601)
     *   - to   (string) End of reporting period (ISO 8601)
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
     * GET /api/v1/geofences/driver/{driverUuid}/history.
     *
     * Returns the geofence event history for a specific driver.
     */
    public function driverHistory(Request $request, string $driverUuid): JsonResponse
    {
        $companyUuid = session('company');
        $perPage     = min((int) $request->input('per_page', 50), 200);

        $events = GeofenceEventLog::where('company_uuid', $companyUuid)
            ->where('driver_uuid', $driverUuid)
            ->with(['driver.vehicle', 'vehicle', 'order'])
            ->orderBy('occurred_at', 'desc')
            ->paginate($perPage);

        $events->getCollection()->transform(fn (GeofenceEventLog $event) => $this->serializeEvent($event));

        return response()->json($events);
    }

    protected function serializeEvent(GeofenceEventLog $event): array
    {
        $driver      = $event->driver;
        $vehicle     = $event->vehicle ?? $driver?->vehicle;
        $subjectType = $event->subject_type ?? ($driver ? 'driver' : 'vehicle');
        $subject     = $subjectType === 'vehicle' ? $vehicle : $driver;

        return [
            'id'                     => $event->uuid,
            'event_type'             => 'geofence.' . $event->event_type,
            'occurred_at'            => optional($event->occurred_at)->toIso8601String(),
            'dwell_duration_minutes' => $event->dwell_duration_minutes,
            'subject'                => [
                'type' => $subjectType,
                'id'   => $subject?->public_id,
                'uuid' => $event->subject_uuid ?? $subject?->uuid,
                'name' => $event->subject_name ?? ($subjectType === 'vehicle' ? ($vehicle?->display_name ?? $vehicle?->plate_number) : $driver?->name),
            ],
            'driver' => $driver ? [
                'id'    => $driver->public_id,
                'uuid'  => $driver->uuid,
                'name'  => $driver->name,
                'phone' => $driver->phone,
            ] : null,
            'vehicle' => $vehicle ? [
                'id'    => $vehicle->public_id,
                'uuid'  => $vehicle->uuid,
                'name'  => $vehicle->display_name ?? $vehicle->name,
                'plate' => $vehicle->plate_number,
            ] : null,
            'geofence' => [
                'uuid' => $event->geofence_uuid,
                'name' => $event->geofence_name,
                'type' => $event->geofence_type,
            ],
            'location' => [
                'latitude'  => $event->latitude,
                'longitude' => $event->longitude,
            ],
            'order' => $event->order ? [
                'id'     => $event->order->public_id,
                'uuid'   => $event->order->uuid,
                'status' => $event->order->status,
            ] : null,
        ];
    }
}
