<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\Events\ResourceLifecycleEvent;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Model;
use Fleetbase\Push\Concerns\PushesToApps;
use Fleetbase\Push\Contracts\SendsToPushApps;
use Fleetbase\Push\PushAppChannel;
use Fleetbase\Support\PushNotification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Fcm\FcmChannel;

class OrderPing extends Notification implements ShouldQueue, SendsToPushApps
{
    use Queueable;
    use PushesToApps;

    /**
     * The iOS category of the push, as in toApn().
     */
    public string $pushCategory = 'order_ping';

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
     * When the driver's offer runs out, as an ISO 8601 time. Null for an offer with no time limit.
     */
    public ?string $expiresAt = null;

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
    public function __construct(Order $order, $distance = null, $expiresAt = null)
    {
        $this->order     = $order->setRelations([]);
        $this->distance  = $distance;
        $this->expiresAt = $expiresAt instanceof \DateTimeInterface ? \Illuminate\Support\Carbon::instance($expiresAt)->toISOString() : (is_string($expiresAt) && $expiresAt !== '' ? $expiresAt : null);
        $this->title    = 'New incoming order!';
        $this->message  = $this->distance ? 'New order available for pickup about ' . Utils::formatMeters($this->distance, false) . ' away' : 'New order is available for pickup.';
        $this->data     = ['id' => $this->order->public_id, 'type' => 'order_ping'];
        // The app counts down to the server's own expiry rather than guessing one.
        if ($this->expiresAt) {
            $this->data['expires_at'] = $this->expiresAt;
        }
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array
     */
    public function via($notifiable)
    {
        $this->notifiable = $notifiable;

        // A driver who has switched this kind of alert off is not rung about it; an open app still hears.
        return \Fleetbase\FleetOps\Support\DriverAlerts::channels($notifiable, 'offers', ['broadcast', FcmChannel::class, ApnChannel::class, PushAppChannel::class]);
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
        // OrderResource declares toWebhookPayload(), so the fallback arms of the
        // old method_exists() chain were never reachable
        $model        = $this->order;
        $resource     = new OrderResource($model);
        $resourceData = $resource->toWebhookPayload();

        $resourceData = ResourceLifecycleEvent::transformResourceChildrenToId($resourceData);

        $data = [
            'id'          => uniqid('event_'),
            'api_version' => config('api.version'),
            'event'       => 'order.ping',
            'created_at'  => now()->toDateTimeString(),
            'data'        => $resourceData,
        ];

        // This driver's offer, shaped as the order resource shows it (`adhoc_offer`).
        if (is_array($data['data'])) {
            $data['data']['adhoc_offer'] = ['status' => 'pending', 'expires_at' => $this->expiresAt];
        }

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
