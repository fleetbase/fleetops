<?php

namespace Fleetbase\FleetOps\Events;

use Fleetbase\FleetOps\Models\Trailer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TrailerLocationChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $eventId;
    public string $sentAt;
    public string $companyUuid;
    public string $trailerUuid;
    public string $trailerId;
    public mixed $location;
    public mixed $altitude;
    public mixed $heading;
    public mixed $speed;
    public array $additionalData;

    public function __construct(Trailer $trailer, array $additionalData = [])
    {
        $this->eventId        = uniqid('event_');
        $this->sentAt         = now()->toDateTimeString();
        $this->companyUuid    = $trailer->company_uuid;
        $this->trailerUuid    = $trailer->uuid;
        $this->trailerId      = $trailer->public_id;
        $this->location       = $trailer->location;
        $this->altitude       = $trailer->altitude;
        $this->heading        = $trailer->heading;
        $this->speed          = $trailer->speed;
        $this->additionalData = $additionalData;
    }

    public function broadcastOn(): array
    {
        return [new Channel('company.' . $this->companyUuid), new Channel('trailer.' . $this->trailerId), new Channel('trailer.' . $this->trailerUuid)];
    }

    public function broadcastAs(): string
    {
        return 'trailer.location_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'id'          => $this->eventId,
            'api_version' => config('api.version'),
            'event'       => $this->broadcastAs(),
            'created_at'  => $this->sentAt,
            'data'        => [
                'id'             => $this->trailerId,
                'location'       => $this->location,
                'altitude'       => $this->altitude,
                'heading'        => $this->heading,
                'speed'          => $this->speed,
                'additionalData' => $this->additionalData,
            ],
        ];
    }
}
