<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Events\GeofenceDwelled;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Zone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CheckGeofenceDwell.
 *
 * A delayed queue job dispatched when a driver enters a geofence that has
 * a dwell_threshold_minutes configured. The job is delayed by that number
 * of minutes. When it runs, it checks whether the driver is still inside
 * the geofence. If so, it fires the GeofenceDwelled event.
 *
 * If the driver exits before the job runs, the dwell_job_id in the state
 * table is cleared, but the job still runs and exits silently because the
 * state record will show is_inside = false.
 *
 * This job runs on the dedicated 'geofence' queue and has tries = 1 because
 * dwell checks should not be retried — a failed check is simply missed.
 */
class CheckGeofenceDwell implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     * Dwell checks are fire-and-forget; retrying would produce false events.
     */
    public int $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 30;

    /**
     * The UUID of the driver to check.
     */
    protected string $driverUuid;

    /**
     * The UUID of the geofence to check.
     */
    protected string $geofenceUuid;

    /**
     * The type of geofence: 'zone' or 'service_area'.
     */
    protected string $geofenceType;

    /**
     * Create a new CheckGeofenceDwell job.
     *
     * @param string $geofenceType 'zone' | 'service_area'
     */
    public function __construct(string $driverUuid, string $geofenceUuid, string $geofenceType)
    {
        $this->driverUuid   = $driverUuid;
        $this->geofenceUuid = $geofenceUuid;
        $this->geofenceType = $geofenceType;
        $this->onQueue('geofence');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Check if the driver is still inside the geofence
        $state = DB::table('driver_geofence_states')
            ->where('driver_uuid', $this->driverUuid)
            ->where('geofence_uuid', $this->geofenceUuid)
            ->where('is_inside', true)
            ->first();

        if (!$state) {
            // Driver has already exited; dwell event should not fire
            return;
        }

        // Load the driver
        $driver = Driver::where('uuid', $this->driverUuid)->withoutGlobalScopes()->first();
        if (!$driver) {
            Log::warning('CheckGeofenceDwell: Driver not found', ['driver_uuid' => $this->driverUuid]);

            return;
        }

        // Load the geofence model
        $geofence = $this->geofenceType === 'service_area'
            ? ServiceArea::where('uuid', $this->geofenceUuid)->first()
            : Zone::where('uuid', $this->geofenceUuid)->first();

        if (!$geofence) {
            Log::warning('CheckGeofenceDwell: Geofence not found', [
                'geofence_uuid' => $this->geofenceUuid,
                'geofence_type' => $this->geofenceType,
            ]);

            return;
        }

        // Parse the entry timestamp
        $enteredAt = \Carbon\Carbon::parse($state->entered_at);

        // Fire the dwell event
        event(new GeofenceDwelled($driver, $geofence, $this->geofenceType, $enteredAt));
    }
}
