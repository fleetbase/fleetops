<?php

namespace Fleetbase\FleetOps\Events;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * Broadcast as `order.offer_{status}` (offer_accepted, offer_declined, offer_expired)
 * when the driver offer on an order is answered or runs out.
 *
 * Channels: order.{public_id}, order.{uuid} and company.{company_uuid}.
 * Data: { id: order public id, driver: driver public id, offer: meta.offer }.
 */
class OrderOfferChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public string $eventId;

    public string $sentAt;

    public string $status;

    public array $channelNames = [];

    public array $data = [];

    public function __construct(Order $order, string $status)
    {
        $offer = (array) $order->getMeta('offer', []);

        $this->eventId      = uniqid('event_');
        $this->sentAt       = Carbon::now()->toDateTimeString();
        $this->status       = $status;
        $this->channelNames = array_values(array_filter([
            'order.' . $order->public_id,
            'order.' . $order->uuid,
            $order->company_uuid ? 'company.' . $order->company_uuid : null,
        ]));
        $this->data = [
            'id'     => $order->public_id,
            'driver' => $offer['driver'] ?? null,
            'offer'  => $offer ?: null,
        ];
    }

    public function broadcastOn(): array
    {
        return array_map(fn (string $name) => new Channel($name), $this->channelNames);
    }

    public function broadcastAs(): string
    {
        return 'order.offer_' . $this->status;
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
