<?php

namespace Fleetbase\FleetOps\Events;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * GeofenceEntered.
 *
 * Fired when a driver's location update causes them to cross into a
 * geofence boundary (Zone or ServiceArea) for the first time.
 *
 * Listeners:
 *   - HandleGeofenceEntered  (order automation, customer notification, event log)
 *   - SendResourceLifecycleWebhook  (webhook delivery to configured endpoints)
 */
class GeofenceEntered
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * The driver who entered the geofence.
     */
    public Driver $driver;

    /**
     * The geofence that was entered (Zone or ServiceArea model instance).
     *
     * @var \Fleetbase\FleetOps\Models\Zone|\Fleetbase\FleetOps\Models\ServiceArea
     */
    public $geofence;

    /**
     * The type of geofence: 'zone' or 'service_area'.
     */
    public string $geofenceType;

    /**
     * The driver's location at the time of entry.
     */
    public Point $location;

    /**
     * The timestamp when the entry was detected.
     */
    public \Carbon\Carbon $timestamp;

    /**
     * Create a new GeofenceEntered event.
     *
     * @param mixed  $geofence     Zone or ServiceArea
     * @param string $geofenceType 'zone' | 'service_area'
     */
    public function __construct(Driver $driver, $geofence, string $geofenceType, Point $location)
    {
        $this->driver       = $driver;
        $this->geofence     = $geofence;
        $this->geofenceType = $geofenceType;
        $this->location     = $location;
        $this->timestamp    = now();
    }

    /**
     * Returns the company UUID for this event.
     * Used by the webhook infrastructure to scope delivery.
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
            'event_type'  => 'geofence.entered',
            'occurred_at' => $this->timestamp->toIso8601String(),
            'driver'      => [
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

        // Attach active order context if the driver has one
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
