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
 * Tells a driver that an order was taken off them (unassigned or reassigned).
 *
 * Push data: { type: "order_unassigned", id: order public id }.
 */
class OrderUnassigned extends Notification implements ShouldQueue
{
    use Queueable;

    public static string $name        = 'Order Unassigned';
    public static string $description = 'Notify a driver when an order is unassigned from them or reassigned to another driver.';
    public static string $package     = 'fleet-ops';

    public Order $order;

    public string $title;

    public string $message;

    public array $data = [];

    public function __construct(Order $order)
    {
        $this->order   = $order;
        $this->title   = 'Order ' . (data_get($order, 'trackingNumber.tracking_number') ?? $order->public_id) . ' unassigned';
        $this->message = 'This order is no longer assigned to you.';
        $this->data    = ['type' => 'order_unassigned', 'id' => $order->public_id];
    }

    public function via($notifiable): array
    {
        return [FcmChannel::class, ApnChannel::class];
    }

    public function toArray($notifiable = null): array
    {
        return [
            'event' => 'order.unassigned_notification',
            'title' => $this->title,
            'body'  => $this->message,
            'data'  => $this->data,
        ];
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
