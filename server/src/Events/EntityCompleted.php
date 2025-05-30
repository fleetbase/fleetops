<?php

namespace Fleetbase\FleetOps\Events;

use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class EntityCompleted implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * The entity which is completed.
     */
    public ?Entity $entity = null;

    /**
     * The activity which triggered the waypoint completed.
     */
    public ?Activity $activity = null;

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
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(Entity $entity, Activity $activity)
    {
        $this->entity           = $entity;
        $this->activity         = $activity;
        $this->eventId          = uniqid('event_');
        $this->sentAt           = Carbon::now()->toDateTimeString();
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel|array
     */
    public function broadcastOn()
    {
        $channels = [
            new Channel('api.' . session('api_credential')),
            new Channel('entity.' . $this->entity->public_id),
            new Channel('entity.' . $this->entity->uuid),
        ];

        $order = $this->getModelRecord();
        if ($order) {
            $channels[] = new Channel('company.' . session('company', data_get($order, 'company.uuid')));
            $channels[] = new Channel('company.' . data_get($order, 'company.public_id'));
            $channels[] = new Channel('order.' . $order->uuid);
            $channels[] = new Channel('order.' . $order->public_id);
        }

        return $channels;
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'entity.completed';
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
                'entity'   => $this->entity->public_id,
                'activity' => $this->activity->toArray(),
            ],
        ];
    }

    /**
     * Get the assosciated order model record for this waypoint.
     */
    public function getModelRecord(): ?Order
    {
        return Order::where('payload_uuid', $this->entity->payload_uuid)->first();
    }
}
