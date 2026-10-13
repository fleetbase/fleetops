<?php

namespace Fleetbase\FleetOps\Events;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * Broadcast as `order.driver_unassigned` when a driver is taken off an order,
 * whether unassigned or replaced by another driver.
 *
 * Channels: driver.{old driver public_id}, driver.{old driver uuid} and the
 * order's company channel. Data: { id: order public id, driver: old driver public id }.
 */
class OrderDriverUnassigned implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public string $eventId;

    public string $sentAt;

    public array $channelNames = [];

    public array $data = [];

    public function __construct(Order $order, Driver $driver)
    {
        $this->eventId      = uniqid('event_');
        $this->sentAt       = Carbon::now()->toDateTimeString();
        $this->channelNames = array_values(array_filter([
            $driver->public_id ? 'driver.' . $driver->public_id : null,
            $driver->uuid ? 'driver.' . $driver->uuid : null,
            $order->company_uuid ? 'company.' . $order->company_uuid : null,
        ]));
        $this->data = [
            'id'     => $order->public_id,
            'driver' => $driver->public_id,
        ];
    }

    public function broadcastOn(): array
    {
        return array_map(fn (string $name) => new Channel($name), $this->channelNames);
    }

    public function broadcastAs(): string
    {
        return 'order.driver_unassigned';
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
