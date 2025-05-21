<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\FleetOps\Flow\Activity;
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

class WaypointCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The order instance this notification is for.
     *
     * @var Waypoint
     */
    public $waypoint;

    /**
     * The activity which triggered this waypoint completed..
     *
     * @var Activity
     */
    public $activity;

    /**
     * Notification name.
     */
    public static string $name = 'Waypoint Completed';

    /**
     * Notification description.
     */
    public static string $description = 'When an order waypoint/destination has been completed.';

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
    public function __construct(Waypoint $waypoint, Activity $activity)
    {
        $this->waypoint    = $waypoint;
        $this->activity    = $activity;
        $this->title       = 'Order ' . $this->waypoint->trackingNumber->tracking_number . ' ' . strtolower($activity->details);
        $this->message     = $activity->details;
        $this->data        = ['id' => $this->waypoint->public_id, 'type' => 'waypoint_completed'];
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
        $channels = [new Channel('api.' . session('api_credential'))];
        $order    = Order::where('payload_uuid', $this->waypoint->payload_uuid)->first();
        if ($order) {
            $channels[] = new Channel('company.' . session('company', data_get($order, 'company.uuid')));
            $channels[] = new Channel('company.' . data_get($order, 'company.public_id'));
            $channels[] = new Channel('order.' . $order->uuid);
            $channels[] = new Channel('order.' . $order->public_id);
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
            'event.waypoint_completed_notification',
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
            ->action('Track Order', Utils::consoleUrl('track-order', ['order' => $this->waypoint->trackingNumber->tracking_number]));
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
}
