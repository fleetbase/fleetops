<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Facades\DB;

/**
 * GeofenceIntersectionService.
 *
 * The core spatial engine for the active geofencing system.
 *
 * On every driver location update, this service:
 *   1. Queries all zones and service areas containing the new point using
 *      a two-pass MySQL spatial strategy (MBRContains for index-assisted
 *      bounding-box pre-filter, then ST_Contains for precise polygon check).
 *   2. Compares the result against the driver's current state in the
 *      driver_geofence_states table.
 *   3. Returns a list of crossing events (entered / exited) for the caller
 *      to dispatch as Laravel events.
 *
 * This class is registered as a singleton in FleetOpsServiceProvider.
 */
class GeofenceIntersectionService
{
    /**
     * Detect geofence boundary crossings for a driver given their new location.
     *
     * Returns an array of crossing events. Each element is an associative array:
     *   - 'type':          'entered' | 'exited'
     *   - 'geofence':      Zone or ServiceArea model instance
     *   - 'geofence_type': 'zone' | 'service_area'
     */
    public function detectCrossings(Driver|Vehicle $subject, Point $newLocation): array
    {
        if ($subject instanceof Driver) {
            return $this->detectDriverCrossings($subject, $newLocation);
        }

        return $this->detectVehicleCrossings($subject, $newLocation);
    }

    public function detectDriverCrossings(Driver $driver, Point $newLocation): array
    {
        return $this->detectSubjectCrossings($driver->company_uuid, $newLocation, 'driver_geofence_states', 'driver_uuid', $driver->uuid);
    }

    public function detectVehicleCrossings(Vehicle $vehicle, Point $newLocation): array
    {
        return $this->detectSubjectCrossings($vehicle->company_uuid, $newLocation, 'vehicle_geofence_states', 'vehicle_uuid', $vehicle->uuid);
    }

    protected function detectSubjectCrossings(string $companyUuid, Point $newLocation, string $stateTable, string $subjectColumn, string $subjectUuid): array
    {
        $crossings   = [];

        // Build the WKT point string. MySQL ST_GeomFromText expects (lng lat) order.
        $wkt = sprintf('POINT(%s %s)', $newLocation->getLng(), $newLocation->getLat());

        // ----------------------------------------------------------------
        // 1. Find all Zones the driver is currently inside.
        //
        //    Two-pass strategy for MySQL performance:
        //    - MBRContains uses the SPATIAL index to filter by minimum
        //      bounding rectangle (fast, may include false positives).
        //    - ST_Contains performs the precise polygon containment check
        //      on the reduced candidate set (accurate, no index needed).
        // ----------------------------------------------------------------
        $insideZones = Zone::where('company_uuid', $companyUuid)
            ->whereNotNull('border')
            ->where(function ($q) {
                $q->where('trigger_on_entry', true)
                    ->orWhere('trigger_on_exit', true)
                    ->orWhereNotNull('dwell_threshold_minutes');
            })
            ->whereRaw('MBRContains(`border`, ST_GeomFromText(?))', [$wkt])
            ->whereRaw('ST_Contains(`border`, ST_GeomFromText(?))', [$wkt])
            ->get();

        // ----------------------------------------------------------------
        // 2. Find all Service Areas the driver is currently inside.
        //
        //    Service areas use MultiPolygon borders, so we use
        //    ST_Contains which handles both Polygon and MultiPolygon.
        // ----------------------------------------------------------------
        $insideServiceAreas = ServiceArea::where('company_uuid', $companyUuid)
            ->whereNotNull('border')
            ->where(function ($q) {
                $q->where('trigger_on_entry', true)
                    ->orWhere('trigger_on_exit', true)
                    ->orWhereNotNull('dwell_threshold_minutes');
            })
            ->whereRaw('MBRContains(`border`, ST_GeomFromText(?))', [$wkt])
            ->whereRaw('ST_Contains(`border`, ST_GeomFromText(?))', [$wkt])
            ->get();

        // Merge into a unified collection with a type discriminator
        $currentlyInside = collect()
            ->merge($insideZones->map(fn ($z) => ['model' => $z, 'geofence_type' => 'zone']))
            ->merge($insideServiceAreas->map(fn ($sa) => ['model' => $sa, 'geofence_type' => 'service_area']));

        $currentlyInsideUuids = $currentlyInside->pluck('model.uuid')->toArray();

        // ----------------------------------------------------------------
        // 3. Load the driver's current geofence state records.
        // ----------------------------------------------------------------
        $currentStates = DB::table($stateTable)
            ->where($subjectColumn, $subjectUuid)
            ->get()
            ->keyBy('geofence_uuid');

        // ----------------------------------------------------------------
        // 4. Detect ENTRIES: geofences the driver is now inside but
        //    was not inside before (or has no state record yet).
        // ----------------------------------------------------------------
        foreach ($currentlyInside as $item) {
            $geofence = $item['model'];
            $state    = $currentStates->get($geofence->uuid);

            if (!$state || !$state->is_inside) {
                $crossings[] = [
                    'type'          => 'entered',
                    'geofence'      => $geofence,
                    'geofence_type' => $item['geofence_type'],
                ];
            }
        }

        // ----------------------------------------------------------------
        // 5. Detect EXITS: geofences the driver was inside but is no
        //    longer inside (not in the current ST_Contains result set).
        // ----------------------------------------------------------------
        foreach ($currentStates as $geofenceUuid => $state) {
            if ($state->is_inside && !in_array($geofenceUuid, $currentlyInsideUuids)) {
                $geofence = $state->geofence_type === 'service_area'
                    ? ServiceArea::where('uuid', $geofenceUuid)->first()
                    : Zone::where('uuid', $geofenceUuid)->first();

                if ($geofence) {
                    $crossings[] = [
                        'type'          => 'exited',
                        'geofence'      => $geofence,
                        'geofence_type' => $state->geofence_type,
                    ];
                }
            }
        }

        return $crossings;
    }

    /**
     * Check if a driver is currently recorded as inside a specific geofence.
     *
     * @param mixed $geofence Zone or ServiceArea
     */
    public function isDriverInsideGeofence(Driver $driver, $geofence): bool
    {
        return DB::table('driver_geofence_states')
            ->where('driver_uuid', $driver->uuid)
            ->where('geofence_uuid', $geofence->uuid)
            ->where('is_inside', true)
            ->exists();
    }

    public function isVehicleInsideGeofence(Vehicle $vehicle, $geofence): bool
    {
        return DB::table('vehicle_geofence_states')
            ->where('vehicle_uuid', $vehicle->uuid)
            ->where('geofence_uuid', $geofence->uuid)
            ->where('is_inside', true)
            ->exists();
    }

    /**
     * Clear all geofence state records for a driver.
     * Called when a driver goes offline or completes a shift.
     */
    public function clearDriverState(Driver $driver): void
    {
        DB::table('driver_geofence_states')
            ->where('driver_uuid', $driver->uuid)
            ->update([
                'is_inside'    => false,
                'exited_at'    => now(),
                'dwell_job_id' => null,
                'updated_at'   => now(),
            ]);
    }

    public function clearVehicleState(Vehicle $vehicle): void
    {
        DB::table('vehicle_geofence_states')
            ->where('vehicle_uuid', $vehicle->uuid)
            ->update([
                'is_inside'    => false,
                'exited_at'    => now(),
                'dwell_job_id' => null,
                'updated_at'   => now(),
            ]);
    }
}
