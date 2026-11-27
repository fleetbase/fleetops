<?php

namespace Fleetbase\FleetOps\Events;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Manifest;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * Broadcast as `manifest.updated` when a route a driver can see changes.
 *
 * Channels: driver.{driver public_id}, driver.{driver uuid} and the manifest's
 * company channel. Data:
 *   { id: manifest public id, driver: driver public id, status, scheduled_date,
 *     change: published | stop_added | stop_removed | resequenced,
 *     orders: [order public ids the change is about], stop_count }
 *
 * Additive: a driver app that does not know the event keeps receiving the
 * per-order assigned / unassigned events it always did.
 */
class ManifestUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public const PUBLISHED    = 'published';
    public const STOP_ADDED   = 'stop_added';
    public const STOP_REMOVED = 'stop_removed';
    public const RESEQUENCED  = 'resequenced';

    public string $eventId;

    public string $sentAt;

    public array $channelNames = [];

    public array $data = [];

    public function __construct(Manifest $manifest, string $change, array $orders = [], ?Driver $driver = null)
    {
        $driver ??= $manifest->driver;

        $this->eventId      = uniqid('event_');
        $this->sentAt       = Carbon::now()->toDateTimeString();
        $this->channelNames = array_values(array_filter([
            $driver?->public_id ? 'driver.' . $driver->public_id : null,
            $driver?->uuid ? 'driver.' . $driver->uuid : null,
            $manifest->company_uuid ? 'company.' . $manifest->company_uuid : null,
        ]));
        $this->data = [
            'id'             => $manifest->public_id,
            'driver'         => $driver?->public_id,
            'status'         => $manifest->status,
            'scheduled_date' => $manifest->scheduled_date?->toDateString(),
            'change'         => $change,
            'orders'         => array_values($orders),
            'stop_count'     => (int) $manifest->stop_count,
        ];
    }

    public function broadcastOn(): array
    {
        return array_map(fn (string $name) => new Channel($name), $this->channelNames);
    }

    public function broadcastAs(): string
    {
        return 'manifest.updated';
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
