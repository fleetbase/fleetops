<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\Events\ResourceLifecycleEvent;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Broadcasting\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Apn\ApnMessage;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

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
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Order $order, $distance = null)
    {
        $this->order    = $order->setRelations([]);
        $this->distance = $distance;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array
     */
    public function via($notifiable)
    {
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
        return [
            new Channel('company.' . session('company', data_get($this->order, 'company.uuid'))),
            new Channel('company.' . data_get($this->order, 'company.public_id')),
            new Channel('api.' . session('api_credential')),
            new Channel('order.' . $this->order->uuid),
            new Channel('order.' . $this->order->public_id),
        ];
    }

    /**
     * Get notification as array.
     *
     * @return void
     */
    public function toArray()
    {
        $order = new OrderResource($this->order);

        return [
            'title' => 'New incoming order!',
            'body'  => $this->distance ? 'New order available for pickup about ' . Utils::formatMeters($this->distance, false) . ' away' : 'New order is available for pickup.',
            'data'  => [
                'id'    => $this->order->public_id,
                'type'  => 'order_ping',
                'order' => $order->toWebhookPayload(),
            ],
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
        return (new FcmMessage(notification: new FcmNotification(
            title: 'New incoming order!',
            body: $this->distance ? 'New order available for pickup about ' . Utils::formatMeters($this->distance, false) . ' away' : 'New order is available for pickup.',
        )))
        ->data(['id' => $this->order->public_id, 'type' => 'order_ping'])
        ->custom([
            'android' => [
                'notification' => [
                    'color' => '#4391EA',
                ],
                'fcm_options' => [
                    'analytics_label' => 'analytics',
                ],
            ],
            'apns' => [
                'fcm_options' => [
                    'analytics_label' => 'analytics',
                ],
            ],
        ]);
    }

    /**
     * Get the apns message representation of the notification.
     *
     * @return array
     */
    public function toApn($notifiable)
    {
        $message = ApnMessage::create()
            ->badge(1)
            ->title('New incoming order!')
            ->body($this->distance ? 'New order available for pickup about ' . Utils::formatMeters($this->distance, false) . ' away' : 'New order is available for pickup.')
            ->custom('type', 'order_ping')
            ->custom('id', $this->order->public_id)
            ->action('view_order', ['id' => $this->order->public_id]);

        return $message;
    }
}
