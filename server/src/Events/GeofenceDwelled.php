<?php

namespace Fleetbase\FleetOps\Events;

use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * GeofenceDwelled.
 *
 * Fired by the CheckGeofenceDwell queue job when a driver has remained
 * inside a geofence for at least the configured dwell_threshold_minutes.
 *
 * Listeners:
 *   - HandleGeofenceDwelled  (event log)
 *   - SendResourceLifecycleWebhook  (webhook delivery)
 */
class GeofenceDwelled
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * The driver who has been dwelling inside the geofence.
     */
    public Driver $driver;

    /**
     * The geofence where the driver is dwelling (Zone or ServiceArea).
     *
     * @var \Fleetbase\FleetOps\Models\Zone|\Fleetbase\FleetOps\Models\ServiceArea
     */
    public $geofence;

    /**
     * The type of geofence: 'zone' or 'service_area'.
     */
    public string $geofenceType;

    /**
     * The timestamp when the driver entered the geofence.
     */
    public \Carbon\Carbon $enteredAt;

    /**
     * The number of minutes the driver has been inside the geofence.
     */
    public int $dwellDurationMinutes;

    /**
     * The timestamp when this dwell event was fired.
     */
    public \Carbon\Carbon $timestamp;

    /**
     * Create a new GeofenceDwelled event.
     *
     * @param mixed  $geofence     Zone or ServiceArea
     * @param string $geofenceType 'zone' | 'service_area'
     */
    public function __construct(Driver $driver, $geofence, string $geofenceType, \Carbon\Carbon $enteredAt)
    {
        $this->driver               = $driver;
        $this->geofence             = $geofence;
        $this->geofenceType         = $geofenceType;
        $this->enteredAt            = $enteredAt;
        $this->dwellDurationMinutes = (int) $enteredAt->diffInMinutes(now());
        $this->timestamp            = now();
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
            'event_type'             => 'geofence.dwelled',
            'occurred_at'            => $this->timestamp->toIso8601String(),
            'entered_at'             => $this->enteredAt->toIso8601String(),
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
