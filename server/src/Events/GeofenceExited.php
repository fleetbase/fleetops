<?php

namespace Fleetbase\FleetOps\Events;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * GeofenceExited.
 *
 * Fired when a driver's location update causes them to cross out of a
 * geofence boundary (Zone or ServiceArea) that they were previously inside.
 *
 * Listeners:
 *   - HandleGeofenceExited  (event log)
 *   - SendResourceLifecycleWebhook  (webhook delivery)
 */
class GeofenceExited
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * The driver who exited the geofence.
     */
    public Driver $driver;

    /**
     * The geofence that was exited (Zone or ServiceArea model instance).
     *
     * @var \Fleetbase\FleetOps\Models\Zone|\Fleetbase\FleetOps\Models\ServiceArea
     */
    public $geofence;

    /**
     * The type of geofence: 'zone' or 'service_area'.
     */
    public string $geofenceType;

    /**
     * The driver's location at the time of exit.
     */
    public Point $location;

    /**
     * The timestamp when the exit was detected.
     */
    public \Carbon\Carbon $timestamp;

    /**
     * How many minutes the driver was inside the geofence before exiting.
     * Null if the entry time was not recorded.
     */
    public ?int $dwellDurationMinutes;

    /**
     * Create a new GeofenceExited event.
     *
     * @param mixed  $geofence     Zone or ServiceArea
     * @param string $geofenceType 'zone' | 'service_area'
     */
    public function __construct(Driver $driver, $geofence, string $geofenceType, Point $location, ?int $dwellDurationMinutes = null)
    {
        $this->driver               = $driver;
        $this->geofence             = $geofence;
        $this->geofenceType         = $geofenceType;
        $this->location             = $location;
        $this->timestamp            = now();
        $this->dwellDurationMinutes = $dwellDurationMinutes;
    }

    /**
     * Returns the company UUID for this event.
     */
    public function getCompanyUuid(): string
    {
        return $this->driver->company_uuid;
    }

    /**
     * Returns the standardised webhook payload for this event.
     */
    public function broadcastWith(): array
    {
        $driver   = $this->driver;
        $geofence = $this->geofence;

        $payload = [
            'event_type'             => 'geofence.exited',
            'occurred_at'            => $this->timestamp->toIso8601String(),
            'dwell_duration_minutes' => $this->dwellDurationMinutes,
            'driver'                 => [
                'id'    => $driver->public_id,
                'uuid'  => $driver->uuid,
                'name'  => $driver->name,
                'phone' => $driver->phone,
            ],
            'vehicle'  => $driver->vehicle ? [
                'id'    => $driver->vehicle->public_id,
                'uuid'  => $driver->vehicle->uuid,
                'name'  => $driver->vehicle->display_name ?? null,
                'plate' => $driver->vehicle->plate_number ?? null,
            ] : null,
            'geofence' => [
                'id'   => $geofence->public_id,
                'uuid' => $geofence->uuid,
                'name' => $geofence->name,
                'type' => $this->geofenceType,
            ],
            'location' => [
                'latitude'  => $this->location->getLat(),
                'longitude' => $this->location->getLng(),
            ],
        ];

        $order = $driver->getCurrentOrder();
        if ($order) {
            $payload['order'] = [
                'id'     => $order->public_id,
                'uuid'   => $order->uuid,
                'status' => $order->status,
            ];
        }

        return $payload;
    }
}
