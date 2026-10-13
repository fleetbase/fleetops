<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Support\PushNotification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Fcm\FcmChannel;

/**
 * Tells the assigned driver that an order they have changed.
 *
 * Push data: { type: "order_changed", id: order public id, changes: [...] }.
 */
class OrderChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public static string $name        = 'Order Changed';
    public static string $description = 'Notify the assigned driver when an order they have is changed.';
    public static string $package     = 'fleet-ops';

    public Order $order;

    public array $changes = [];

    public string $title;

    public string $message;

    public array $data = [];

    public function __construct(Order $order, array $changes = [], string $summary = '')
    {
        $this->order   = $order;
        $this->changes = array_values($changes);
        $this->title   = 'Order ' . (data_get($order, 'trackingNumber.tracking_number') ?? $order->public_id) . ' changed';
        $this->message = $summary !== '' ? $summary : 'This order was changed, tap for details.';
        $this->data    = ['type' => 'order_changed', 'id' => $order->public_id, 'changes' => $this->changes];
    }

    public function via($notifiable): array
    {
        return ['broadcast', FcmChannel::class, ApnChannel::class];
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('driver.' . data_get($this->order, 'driver_assigned_uuid')),
            new Channel('driver.' . data_get($this->order, 'driverAssigned.public_id')),
        ];
    }

    public function toArray($notifiable = null): array
    {
        return [
            'event' => 'order.changed_notification',
            'title' => $this->title,
            'body'  => $this->message,
            'data'  => $this->data,
        ];
    }

    public function toFcm($notifiable)
    {
        return PushNotification::createFcmMessage($this->title, $this->message, $this->fcmData());
    }

    public function toApn($notifiable)
    {
        return PushNotification::createApnMessage($this->title, $this->message, $this->data, 'view_order');
    }

    /**
     * FCM data values must be strings, so on FCM the change list travels comma
     * separated ("dropoff,items"); APNs and the broadcast carry it as a list.
     */
    public function fcmData(): array
    {
        return array_merge($this->data, ['changes' => implode(',', $this->changes)]);
    }
}
