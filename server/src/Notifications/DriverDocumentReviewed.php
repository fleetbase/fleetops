<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\FleetOps\Models\Driver;
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

/**
 * Tells a driver that one of the documents they sent was approved or sent back.
 */
class DriverDocumentReviewed extends Notification implements ShouldQueue, SendsToPushApps
{
    use Queueable;
    use PushesToApps;

    public static string $name        = 'Driver Document Reviewed';
    public static string $description = 'Notify a driver when a document they sent is approved or sent back.';
    public static string $package     = 'fleet-ops';

    public string $driverUuid;
    public string $driverPublicId;
    public string $title;
    public string $message;
    public array $data = [];

    public function __construct(Driver $driver, string $document, string $decision, ?string $note = null, bool $released = false)
    {
        $this->driverUuid     = $driver->uuid;
        $this->driverPublicId = $driver->public_id;

        if ($decision === 'approved') {
            $this->title   = $released ? 'You are cleared to drive' : $document . ' approved';
            $this->message = $released ? 'All your documents have been approved.' : 'Your ' . $document . ' has been approved.';
        } else {
            $this->title   = $document . ' needs another look';
            $this->message = $note ?: 'Your organization sent it back. Open the app to send it again.';
        }

        $this->data = ['id' => $driver->public_id, 'type' => 'driver_document_reviewed', 'document' => $document, 'decision' => $decision, 'released' => $released];
    }

    public function via($notifiable)
    {
        return ['broadcast', FcmChannel::class, ApnChannel::class, PushAppChannel::class];
    }

    public function broadcastType()
    {
        return 'driver.document_reviewed';
    }

    public function broadcastOn()
    {
        return [
            new Channel('driver.' . $this->driverUuid),
            new Channel('driver.' . $this->driverPublicId),
        ];
    }

    public function toArray()
    {
        return [
            'event' => 'driver.document_reviewed',
            'title' => $this->title,
            'body'  => $this->message,
            'data'  => $this->data,
        ];
    }

    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage([
            'id'          => uniqid('event_'),
            'api_version' => config('api.version'),
            'event'       => 'driver.document_reviewed',
            'created_at'  => now()->toDateTimeString(),
            'data'        => $this->data,
        ]);
    }

    public function toFcm($notifiable)
    {
        return PushNotification::createFcmMessage($this->title, $this->message, $this->data);
    }

    public function toApn($notifiable)
    {
        return PushNotification::createApnMessage($this->title, $this->message, $this->data, 'driver_document_reviewed');
    }
}
