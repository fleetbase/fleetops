<?php

namespace Fleetbase\FleetOps\Events;

use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class VehicleLocationChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * The event id.
     *
     * @var string
     */
    public $eventId;

    /**
     * The datetime instance the broadcast ws triggered.
     *
     * @var string
     */
    public $sentAt;

    /**
     * The uuid of the vehicle.
     *
     * @var string
     */
    public $vehicleUuid;

    /**
     * The public id of the vehicle.
     *
     * @var string
     */
    public $vehicleId;

    /**
     * The plate number of the vehicle.
     *
     * @var string
     */
    public $vehiclePlateNumber;

    /**
     * The name of the vehicle.
     *
     * @var string
     */
    public $vehicleName;

    /**
     * The new vehicle location.
     *
     * @var string
     */
    public $location;

    /**
     * The vehicle altitude.
     *
     * @var string
     */
    public $altitude;

    /**
     * The nvehicle heading.
     *
     * @var string
     */
    public $heading;

    /**
     * The vehicle speed.
     *
     * @var string
     */
    public $speed;

    /**
     * Optional, additional data.
     *
     * @var array
     */
    public $additionalData = [];

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(Vehicle $vehicle, array $additionalData = [])
    {
        $this->eventId            = uniqid('event_');
        $this->sentAt             = Carbon::now()->toDateTimeString();
        $this->additionalData     = $additionalData;
        $this->vehicleUuid        = $vehicle->uuid;
        $this->vehicleId          = $vehicle->public_id;
        $this->vehiclePlateNumber = $vehicle->plate_number;
        $this->vehicleName        = $vehicle->display_name;
        $this->location           = $vehicle->location;
        $this->altitude           = $vehicle->altitude;
        $this->heading            = $vehicle->heading;
        $this->speed              = $vehicle->speed;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel|array
     */
    public function broadcastOn()
    {
        return [
            new Channel('company.' . session('company')),
            new Channel('api.' . session('api_credential')),
            new Channel('vehicle.' . $this->vehicleId),
            new Channel('vehicle.' . $this->vehicleUuid),
        ];
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'vehicle.location_changed';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith()
    {
        return [
            'id'          => $this->eventId,
            'api_version' => config('api.version'),
            'event'       => $this->broadcastAs(),
            'created_at'  => $this->sentAt,
            'data'        => [
                'id'              => $this->vehicleId,
                'plate_number'    => $this->vehiclePlateNumber,
                'name'            => $this->vehicleName,
                'location'        => $this->location,
                'altitude'        => $this->altitude,
                'heading'         => $this->heading,
                'speed'           => $this->speed,
                'additionalData'  => $this->additionalData,
            ],
        ];
    }
}
