<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\FleetOps\Events\OrderDispatchFailed as OrderDispatchFailedEvent;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Broadcasting\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderDispatchFailed extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The order instance this notification is for.
     *
     * @var Order
     */
    public $order;

    /**
     * Reason order dispatch failed.
     *
     * @var string
     */
    public $reason;

    /**
     * Notification name.
     */
    public static string $name = 'Order dispatch Failed';

    /**
     * Notification description.
     */
    public static string $description = 'Notify when an order dispatch has been failed.';

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
    public function __construct(Order $order, OrderDispatchFailedEvent $event)
    {
        $this->order   = $order;
        $this->reason  = $event->getReason();
        $this->title   = 'Order ' . $this->order->trackingNumber->tracking_number . ' dispatch has failed!';
        $this->message = $this->reason;
        $this->data    = ['id' => $this->order->public_id, 'type' => 'order_dispatch_failed'];
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
     * Get the notification's delivery channels.
     *
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
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
            'event' => 'order.assigned_failed_notification',
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
            ->action('Track Order', Utils::consoleUrl('track-order', ['order' => $this->order->trackingNumber->tracking_number]));
    }
}
