<?php

namespace Fleetbase\FleetOps\Listeners;

use Fleetbase\FleetOps\Events\GeofenceExited;
use Fleetbase\FleetOps\Models\GeofenceEventLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

/**
 * HandleGeofenceExited
 *
 * Handles the business logic triggered when a driver exits a geofence:
 *   1. Writes a record to the geofence_events_log table, including the
 *      dwell duration calculated from the entry timestamp.
 */
class HandleGeofenceExited implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The name of the queue the job should be sent to.
     *
     * @var string
     */
    public string $queue = 'geofence';

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * Handle the event.
     *
     * @param GeofenceExited $event
     *
     * @return void
     */
    public function handle(GeofenceExited $event): void
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
            'event_type'             => 'exited',
            'latitude'               => $event->location->getLat(),
            'longitude'              => $event->location->getLng(),
            'dwell_duration_minutes' => $event->dwellDurationMinutes,
            'occurred_at'            => $event->timestamp,
        ]);
    }
}
