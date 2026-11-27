<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\Push\Concerns\PushesToApps;
use Fleetbase\Push\Contracts\SendsToPushApps;
use Fleetbase\Push\PushAppChannel;
use Fleetbase\Support\PushNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Fcm\FcmChannel;

/**
 * Tells a driver a route was published to them.
 *
 * Push data: { type: "manifest_published", id: manifest public id, scheduled_date }.
 */
class ManifestPublished extends Notification implements ShouldQueue, SendsToPushApps
{
    use Queueable;
    use PushesToApps;

    public static string $name        = 'Route Published';
    public static string $description = 'Notify a driver when a route is published to them.';
    public static string $package     = 'fleet-ops';

    public Manifest $manifest;

    public string $title;

    public string $message;

    public array $data = [];

    public function __construct(Manifest $manifest)
    {
        $date           = $manifest->scheduled_date?->toDateString();
        $stops          = (int) $manifest->stop_count;
        $this->manifest = $manifest;
        $this->title    = $date ? 'Your route for ' . $date . ' is ready' : 'Your route is ready';
        $this->message  = $stops === 1 ? '1 stop.' : $stops . ' stops.';
        $this->data     = ['type' => 'manifest_published', 'id' => $manifest->public_id, 'scheduled_date' => $date];
    }

    public function via($notifiable): array
    {
        return [FcmChannel::class, ApnChannel::class, PushAppChannel::class];
    }

    public function toArray($notifiable = null): array
    {
        return [
            'event' => 'manifest.published_notification',
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
        return PushNotification::createApnMessage($this->title, $this->message, $this->data, 'view_manifest');
    }
}
