<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Support\PushNotification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Fcm\FcmChannel;

class OrderCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The order instance this notification is for.
     *
     * @var Order
     */
    public $order;

    /**
     * The waypoint instance this notification is for.
     *
     * @var Waypoint
     */
    public $waypoint;

    /**
     * Notification name.
     */
    public static string $name = 'Order Completed';

    /**
     * Notification description.
     */
    public static string $description = 'Notify when an order has been completed.';

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
    public function __construct(Order $order, ?Waypoint $waypoint = null)
    {
        $this->order      = $order;
        $this->waypoint   = $waypoint;
        $this->title      = 'Order ' . $this->getTrackingNumber() . ' has been completed.';
        $this->message    = 'Order ' . $this->getTrackingNumber() . ' has been completed by agent.';
        $this->data       = ['id' => $this->order->public_id, 'type' => 'order_completed'];
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array
     */
    public function via($notifiable)
    {
        return ['broadcast', 'mail', FcmChannel::class, ApnChannel::class];
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
            'event' => 'order.completed_notification',
            'title' => $this->title,
            'body'  => $this->message,
            'data'  => $this->data,
        ];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @return MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage())
            ->subject($this->title)
            ->line($this->message)
            ->line('No further action is necessary.')
            ->action('Track Order', Utils::consoleUrl('track-order', ['order' => $this->getTrackingNumber()]));
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
        return PushNotification::createApnMessage($this->title, $this->message, $this->data, 'view_order');
    }

    /**
     * Get the tracking number which should be used for this event.
     * In the case that the order has a currentWaypoint attached use its tracking number otherwise use the orders.
     */
    private function getTrackingNumber(): ?string
    {
        if ($this->waypoint instanceof Waypoint) {
            return $this->waypoint->tracking;
        }

        return $this->order->tracking;
    }
}
