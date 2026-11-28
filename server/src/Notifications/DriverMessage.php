<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Push\Concerns\PushesToApps;
use Fleetbase\Push\Contracts\SendsToPushApps;
use Fleetbase\Push\PushAppChannel;
use Fleetbase\Support\PushNotification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Fcm\FcmChannel;

/**
 * A notification someone in the console wrote and sent to a driver by hand.
 *
 * Sent straight away, not queued, so the person sending it learns at once
 * whether it could be sent.
 */
class DriverMessage extends Notification implements SendsToPushApps
{
    use PushesToApps;

    public static string $name        = 'Driver Message';
    public static string $description = 'A notification written in the console and sent to a driver.';
    public static string $package     = 'fleet-ops';

    public string $driverUuid;
    public string $driverPublicId;
    public string $title;
    public string $message;
    public array $data = [];

    public function __construct(Driver $driver, string $title, string $message, ?string $sentBy = null)
    {
        $this->driverUuid     = $driver->uuid;
        $this->driverPublicId = $driver->public_id;
        $this->title          = $title;
        $this->message        = $message;
        $this->data           = ['id' => $driver->public_id, 'type' => 'driver_message', 'sent_by' => $sentBy];
    }

    public function via($notifiable)
    {
        return ['broadcast', FcmChannel::class, ApnChannel::class, PushAppChannel::class];
    }

    public function broadcastType()
    {
        return 'driver.message';
    }

    public function broadcastOn()
    {
        return [new Channel('driver.' . $this->driverUuid), new Channel('driver.' . $this->driverPublicId)];
    }

    public function toArray()
    {
        return ['event' => 'driver.message', 'title' => $this->title, 'body' => $this->message, 'data' => $this->data];
    }

    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage([
            'id'          => uniqid('event_'),
            'api_version' => config('api.version'),
            'event'       => 'driver.message',
            'created_at'  => now()->toDateTimeString(),
            'data'        => ['title' => $this->title, 'body' => $this->message] + $this->data,
        ]);
    }

    public function toFcm($notifiable)
    {
        return PushNotification::createFcmMessage($this->title, $this->message, $this->data);
    }

    public function toApn($notifiable)
    {
        return PushNotification::createApnMessage($this->title, $this->message, $this->data, 'driver_message');
    }
}
