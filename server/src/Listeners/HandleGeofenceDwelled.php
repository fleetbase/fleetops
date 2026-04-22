<?php

namespace Fleetbase\FleetOps\Listeners;

use Fleetbase\FleetOps\Events\GeofenceDwelled;
use Fleetbase\FleetOps\Models\GeofenceEventLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

/**
 * HandleGeofenceDwelled.
 *
 * Handles the business logic triggered when a driver has dwelled inside
 * a geofence for the configured threshold duration:
 *   1. Writes a record to the geofence_events_log table with the dwell duration.
 */
class HandleGeofenceDwelled implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The name of the queue the job should be sent to.
     */
    public string $queue = 'geofence';

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Handle the event.
     */
    public function handle(GeofenceDwelled $event): void
    {
        $driver = $event->driver;
        $order  = $driver->getCurrentOrder();

        GeofenceEventLog::create([
            'uuid'                   => Str::uuid()->toString(),
            'company_uuid'           => $driver->company_uuid,
            'driver_uuid'            => $driver->uuid,
            'vehicle_uuid'           => $driver->vehicle_uuid ?? null,
            'order_uuid'             => $order?->uuid,
            'geofence_uuid'          => $event->geofence->uuid,
            'geofence_type'          => $event->geofenceType,
            'geofence_name'          => $event->geofence->name,
            'event_type'             => 'dwelled',
            'latitude'               => null,
            'longitude'              => null,
            'dwell_duration_minutes' => $event->dwellDurationMinutes,
            'occurred_at'            => $event->timestamp,
        ]);
    }
}
