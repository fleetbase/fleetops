<?php

namespace Fleetbase\FleetOps\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * Broadcast as `order.offer_closed` when a driver's offer for an adhoc order closes without
 * them accepting it: it ran out, or the order was taken by someone else or canceled. A driver
 * app takes the offer off screen.
 *
 * Channels: driver.{public_id} and driver.{uuid}. Data: { id: order public id, status: expired|withdrawn }.
 */
class OrderOfferClosed implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public string $eventId;

    public string $sentAt;

    public array $channelNames = [];

    public array $data = [];

    public function __construct(string $orderPublicId, string $status, ?string $driverPublicId, string $driverUuid)
    {
        $this->eventId      = uniqid('event_');
        $this->sentAt       = Carbon::now()->toDateTimeString();
        $this->channelNames = array_values(array_filter([
            $driverPublicId ? 'driver.' . $driverPublicId : null,
            'driver.' . $driverUuid,
        ]));
        $this->data = ['id' => $orderPublicId, 'status' => $status];
    }

    public function broadcastOn(): array
    {
        return array_map(fn (string $name) => new Channel($name), $this->channelNames);
    }

    public function broadcastAs(): string
    {
        return 'order.offer_closed';
    }

    public function broadcastWith(): array
    {
        return [
            'id'          => $this->eventId,
            'api_version' => config('api.version'),
            'event'       => $this->broadcastAs(),
            'created_at'  => $this->sentAt,
            'data'        => $this->data,
        ];
    }
}
