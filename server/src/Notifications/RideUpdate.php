<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\User;
use Fleetbase\Support\PushNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Fcm\FcmChannel;

/**
 * A push to the rider when their ride changes: a driver accepted, is about to arrive, has
 * arrived, the trip started or ended, or nobody could take it. Sent to the rider's own devices
 * (`customers/register-device`), so it needs no Storefront and no company notification setting.
 */
class RideUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    public const MESSAGES = [
        'started'         => ['Your driver is on the way', '{driver} accepted your ride.'],
        'arriving'        => ['Your driver is almost there', '{driver} is about a minute away.'],
        'arrived'         => ['Your driver has arrived', '{driver} is waiting at the pickup point.'],
        'in_progress'     => ['Your trip has started', 'Enjoy the ride.'],
        'completed'       => ['You have arrived', 'Your trip is complete. See your fare in the app.'],
        'canceled'        => ['Your ride was canceled', "You haven't been charged. You can book again."],
        'no_driver_found' => ['No driver found', 'No drivers are free right now. Try again in a few minutes.'],
    ];

    public string $title;
    public string $message;
    public array $data;

    public function __construct(public Order $order, public string $kind)
    {
        [$title, $message] = self::MESSAGES[$kind];
        $driver            = trim((string) strtok((string) static::driverName($order), ' ')) ?: 'Your driver';

        $this->title   = $title;
        $this->message = str_replace('{driver}', $driver, $message);
        $this->data    = ['id' => $order->public_id, 'type' => 'ride_' . $kind];
    }

    /**
     * The driver's name, read without loading their whole record.
     */
    public static function driverName(Order $order): ?string
    {
        if ($order->relationLoaded('driverAssigned')) {
            return data_get($order, 'driverAssigned.name');
        }

        $userUuid = $order->driver_assigned_uuid ? Driver::withoutGlobalScopes()->where('uuid', $order->driver_assigned_uuid)->value('user_uuid') : null;

        return $userUuid ? User::where('uuid', $userUuid)->value('name') : null;
    }

    public static function knows(string $kind): bool
    {
        return isset(self::MESSAGES[$kind]);
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
        return PushNotification::createApnMessage($this->title, $this->message, $this->data, 'view_ride');
    }
}
