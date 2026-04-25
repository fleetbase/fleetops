<?php

namespace Fleetbase\FleetOps\Events;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
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
class GeofenceDwelled implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $modelHumanName   = 'Geofence';
    public ?string $modelRecordName = null;
    public string $eventName        = 'dwelled';
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
     * The driver who has been dwelling inside the geofence.
     */
    public ?Driver $driver = null;

    /**
     * The vehicle associated with the geofence event, if any.
     */
    public ?Vehicle $vehicle = null;

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
     * The subject that triggered the event: 'driver' or 'vehicle'.
     */
    public string $subjectType;

    /**
     * Create a new GeofenceDwelled event.
     *
     * @param mixed  $geofence     Zone or ServiceArea
     * @param string $geofenceType 'zone' | 'service_area'
     */
    public function __construct(Driver|Vehicle $subject, $geofence, string $geofenceType, \Carbon\Carbon $enteredAt)
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
        $this->enteredAt            = $enteredAt;
        $this->dwellDurationMinutes = (int) $enteredAt->diffInMinutes(now());
        $this->timestamp            = now();
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
        return 'geofence.dwelled';
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
            'event_type'             => 'geofence.dwelled',
            'occurred_at'            => $this->timestamp->toIso8601String(),
            'entered_at'             => $this->enteredAt->toIso8601String(),
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
