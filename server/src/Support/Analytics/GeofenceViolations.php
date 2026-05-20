<?php

namespace Fleetbase\FleetOps\Support\Analytics;

use Fleetbase\FleetOps\Models\GeofenceEventLog;
use Illuminate\Support\Carbon;

/**
 * Geofence dwell + violation summary. Today's violation count, period total,
 * top-N dwell outliers (driver × zone × duration), and a bar chart of events
 * by zone.
 */
class GeofenceViolations extends AbstractAnalytics
{
    public function get(): array
    {
        $companyUuid = $this->company->uuid;
        $start       = $this->start ?? Carbon::now()->subDays(7)->toDateTime();
        $end         = $this->end ?? Carbon::now()->toDateTime();

        $violationsToday = GeofenceEventLog::where('company_uuid', $companyUuid)
            ->where('event_type', 'dwelled')
            ->whereDate('occurred_at', Carbon::today())
            ->count();

        $violationsPeriod = GeofenceEventLog::where('company_uuid', $companyUuid)
            ->where('event_type', 'dwelled')
            ->whereBetween('occurred_at', [$start, $end])
            ->count();

        $topDwells = GeofenceEventLog::where('company_uuid', $companyUuid)
            ->where('event_type', 'dwelled')
            ->whereBetween('occurred_at', [$start, $end])
            ->whereNotNull('dwell_duration_minutes')
            ->orderByDesc('dwell_duration_minutes')
            ->limit(8)
            ->get(['subject_name', 'driver_uuid', 'geofence_name', 'dwell_duration_minutes', 'occurred_at']);

        $byZoneRows = GeofenceEventLog::where('company_uuid', $companyUuid)
            ->whereBetween('occurred_at', [$start, $end])
            ->selectRaw('geofence_name, COUNT(*) as total')
            ->groupBy('geofence_name')
            ->orderByRaw('total DESC')
            ->limit(8)
            ->get();

        return [
            'violations_today'  => $violationsToday,
            'violations_period' => $violationsPeriod,
            'top_dwells'        => $topDwells->map(fn ($e) => [
                'driver_uuid'      => $e->driver_uuid,
                'driver_name'      => $e->subject_name,
                'zone_name'        => $e->geofence_name,
                'duration_minutes' => (int) $e->dwell_duration_minutes,
                'occurred_at'      => $e->occurred_at,
            ])->all(),
            'by_zone' => [
                'labels' => $byZoneRows->pluck('geofence_name')->map(fn ($n) => $n ?: 'Unnamed')->all(),
                'data'   => $byZoneRows->pluck('total')->map(fn ($v) => (int) $v)->all(),
            ],
        ];
    }
}
