<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\Models\ScheduleItem;
use Fleetbase\Support\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DriverShiftChanged extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The schedule item that was created or updated.
     */
    public ScheduleItem $scheduleItem;

    /**
     * Whether this is a new shift (created) or an update to an existing one.
     */
    public bool $isNew;

    /**
     * Notification name.
     */
    public static string $name = 'Driver Shift Changed';

    /**
     * Notification description.
     */
    public static string $description = 'Notify a driver when their shift schedule is created or updated.';

    /**
     * Notification package.
     */
    public static string $package = 'fleet-ops';

    /**
     * The notification title.
     */
    public string $title;

    /**
     * The notification body.
     */
    public string $message;

    /**
     * Create a new notification instance.
     */
    public function __construct(ScheduleItem $scheduleItem, bool $isNew = true)
    {
        $this->scheduleItem = $scheduleItem;
        $this->isNew        = $isNew;

        $start = $scheduleItem->start_at ? $scheduleItem->start_at->format('D, M j g:ia') : '—';
        $end   = $scheduleItem->end_at ? $scheduleItem->end_at->format('g:ia') : '—';

        if ($isNew) {
            $this->title   = 'New shift scheduled';
            $this->message = "A new shift has been added to your schedule: {$start} – {$end}.";
        } else {
            $this->title   = 'Your shift has been updated';
            $this->message = "Your shift has been updated: {$start} – {$end}.";
        }
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        $message = (new MailMessage())
            ->subject($this->title)
            ->line($this->message);

        if ($this->scheduleItem->notes) {
            $message->line('Notes: ' . $this->scheduleItem->notes);
        }

        $message->action('View Schedule', Utils::consoleUrl('fleet-ops/operations/scheduler'));

        return $message;
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        return [
            'id'             => $this->scheduleItem->public_id,
            'type'           => 'driver_shift_changed',
            'is_new'         => $this->isNew,
            'start_at'       => $this->scheduleItem->start_at,
            'end_at'         => $this->scheduleItem->end_at,
        ];
    }
}
