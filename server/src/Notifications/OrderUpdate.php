<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Support\PushNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Fcm\FcmChannel;

/**
 * A push about an order, with the title and message its order config's trigger gave. Sent to
 * the notifiable's own devices, a customer's from `customers/register-device` or a driver's.
 */
class OrderUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    public array $data;

    public function __construct(public Order $order, public string $title, public string $message, public string $event = 'order_update')
    {
        $this->data = ['id' => $order->public_id, 'type' => $event];
    }

    public function via($notifiable)
    {
        return [FcmChannel::class, ApnChannel::class];
    }

    public function toFcm($notifiable)
    {
        return PushNotification::createFcmMessage($this->title, $this->message, $this->data);
    }

    public function toApn($notifiable)
    {
        return PushNotification::createApnMessage($this->title, $this->message, $this->data, 'view_order');
    }
}
