<?php

namespace Fleetbase\FleetOps\Events;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
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
class GeofenceExited implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $modelHumanName   = 'Geofence';
    public ?string $modelRecordName = null;
    public string $eventName        = 'exited';
    public $userSession;
    public $companySession;
    public $requestMethod;
    public $apiCredential;
    public $apiSecret;
    public $apiKey;
    public $apiEnvironment;
    public $isSandbox;
    public array $data = [];

    /**
     * The driver who exited the geofence.
     */
    public ?Driver $driver = null;

    /**
     * The vehicle associated with the geofence event, if any.
     */
    public ?Vehicle $vehicle = null;

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
     * The subject that triggered the event: 'driver' or 'vehicle'.
     */
    public string $subjectType;

    /**
     * Create a new GeofenceExited event.
     *
     * @param mixed  $geofence     Zone or ServiceArea
     * @param string $geofenceType 'zone' | 'service_area'
     */
    public function __construct(Driver|Vehicle $subject, $geofence, string $geofenceType, Point $location, ?int $dwellDurationMinutes = null)
    {
        if ($subject instanceof Driver) {
            $this->driver      = $subject;
            $this->vehicle     = $subject->vehicle;
            $this->subjectType = 'driver';
        } else {
            $subject->loadMissing('driver');
            $this->vehicle     = $subject;
            $this->driver      = $subject->driver;
            $this->subjectType = 'vehicle';
        }

        $this->geofence             = $geofence;
        $this->geofenceType         = $geofenceType;
        $this->location             = $location;
        $this->timestamp            = now();
        $this->dwellDurationMinutes = $dwellDurationMinutes;
        $this->modelRecordName      = $geofence->name ?? $geofence->public_id ?? $geofence->uuid ?? null;
        $this->userSession          = session('user');
        $this->companySession       = session('company', $this->getCompanyUuid());
        $this->requestMethod        = request()?->method() ?? 'CLI';
        $this->apiCredential        = session('api_credential', 'console');
        $this->apiSecret            = session('api_secret', 'internal');
        $this->apiKey               = session('api_key');
        $this->apiEnvironment       = session('api_environment', 'live');
        $this->isSandbox            = session('is_sandbox', false);
        $this->data                 = $this->getEventData();
    }

    /**
     * Returns the company UUID for this event.
     */
    public function getCompanyUuid(): string
    {
        return $this->vehicle?->company_uuid ?? $this->driver->company_uuid;
    }

    public function broadcastOn(): array
    {
        $channels = [new Channel('company.' . $this->getCompanyUuid())];

        if ($this->driver) {
            $channels[] = new Channel('driver.' . $this->driver->public_id);
            $channels[] = new Channel('driver.' . $this->driver->uuid);
        }

        if ($this->vehicle) {
            $channels[] = new Channel('vehicle.' . $this->vehicle->public_id);
            $channels[] = new Channel('vehicle.' . $this->vehicle->uuid);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'geofence.exited';
    }

    /**
     * Returns the standardised webhook payload for this event.
     */
    public function broadcastWith(): array
    {
        return $this->getEventData();
    }

    public function getEventData(): array
    {
        $driver   = $this->driver;
        $vehicle  = $this->vehicle;
        $geofence = $this->geofence;
        $subject  = $this->subjectType === 'vehicle' ? $vehicle : $driver;

        $payload = [
            'event'                  => $this->broadcastAs(),
            'event_type'             => 'geofence.exited',
            'occurred_at'            => $this->timestamp->toIso8601String(),
            'dwell_duration_minutes' => $this->dwellDurationMinutes,
            'subject'                => [
                'type' => $this->subjectType,
                'id'   => $subject?->public_id ?? null,
                'uuid' => $subject?->uuid ?? null,
                'name' => $this->subjectType === 'vehicle' ? ($vehicle?->display_name ?? $vehicle?->plate_number) : $driver?->name,
            ],
            'driver'                 => $driver ? [
                'id'    => $driver->public_id,
                'uuid'  => $driver->uuid,
                'name'  => $driver->name,
                'phone' => $driver->phone,
            ] : null,
            'vehicle'                => $vehicle ? [
                'id'    => $vehicle->public_id,
                'uuid'  => $vehicle->uuid,
                'name'  => $vehicle->display_name ?? null,
                'plate' => $vehicle->plate_number ?? null,
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

        $order = $driver?->getCurrentOrder();
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
