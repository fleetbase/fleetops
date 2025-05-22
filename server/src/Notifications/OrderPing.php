<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\Events\ResourceLifecycleEvent;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Model;
use Fleetbase\Support\PushNotification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Fcm\FcmChannel;

class OrderPing extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The order instance this notification is for.
     */
    public Order $order;

    /**
     * Distance of order pickup from driver.
     *
     * @var int
     */
    public $distance;

    /**
     * Notification name.
     */
    public static string $name = 'Order Ping';

    /**
     * Notification description.
     */
    public static string $description = 'Notify when an order has been pinged.';

    /**
     * Notification package.
     */
    public static string $package = 'fleet-ops';

    /**
     * The title of the notification.
     */
    public string $title;

    /**
     * The message body of the notification.
     */
    public string $message;

    /**
     * Additional data to be sent with the notification.
     */
    public array $data = [];

    /**
     * The driver or user being notified about the order ping.
     */
    public Model $notifiable;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Order $order, $distance = null)
    {
        $this->order    = $order->setRelations([]);
        $this->distance = $distance;
        $this->title    = 'New incoming order!';
        $this->message  = $this->distance ? 'New order available for pickup about ' . Utils::formatMeters($this->distance, false) . ' away' : 'New order is available for pickup.';
        $this->data     = ['id' => $this->order->public_id, 'type' => 'order_ping'];
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array
     */
    public function via($notifiable)
    {
        $this->notifiable = $notifiable;

        return ['broadcast', FcmChannel::class, ApnChannel::class];
    }

    /**
     * Get the type of the notification being broadcast.
     *
     * @return string
     */
    public function broadcastType()
    {
        return 'order.ping';
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel|array
     */
    public function broadcastOn()
    {
        $channels = [
            new Channel('company.' . session('company', data_get($this->order, 'company.uuid'))),
            new Channel('company.' . data_get($this->order, 'company.public_id')),
            new Channel('api.' . session('api_credential')),
            new Channel('order.' . $this->order->uuid),
            new Channel('order.' . $this->order->public_id),
        ];

        if ($this->notifiable) {
            $channels[] = new Channel('driver.' . $this->notifiable->uuid);
            $channels[] = new Channel('driver.' . $this->notifiable->public_id);
        }

        return $channels;
    }

    /**
     * Get notification as array.
     *
     * @return void
     */
    public function toArray()
    {
        return [
            'event' => 'order.ping_notification',
            'title' => $this->title,
            'body'  => $this->message,
            'data'  => $this->data,
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     *
     * @return BroadcastMessage
     */
    public function toBroadcast($notifiable)
    {
        $model        = $this->order;
        $resource     = new OrderResource($model);
        $resourceData = [];

        if ($resource) {
            if (method_exists($resource, 'toWebhookPayload')) {
                $resourceData = $resource->toWebhookPayload();
            } elseif (method_exists($resource, 'toArray')) {
                $resourceData = $resource->toArray(request());
            }
        }

        $resourceData = ResourceLifecycleEvent::transformResourceChildrenToId($resourceData);

        $data = [
            'id'          => uniqid('event_'),
            'api_version' => config('api.version'),
            'event'       => 'order.ping',
            'created_at'  => now()->toDateTimeString(),
            'data'        => $resourceData,
        ];

        return new BroadcastMessage($data);
    }

    /**
     * Get the firebase cloud message representation of the notification.
     *
     * @return array
     */
    public function toFcm($notifiable)
    {
        return PushNotification::createFcmMessage($this->title, $this->message, $this->data);
    }

    /**
     * Get the apns message representation of the notification.
     *
     * @return array
     */
    public function toApn($notifiable)
    {
        return PushNotification::createApnMessage($this->title, $this->message, $this->data, 'order_ping');
    }
}
