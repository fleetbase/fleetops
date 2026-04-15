<?php

namespace Fleetbase\FleetOps\Mail;

use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MaintenanceScheduleReminder extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * The maintenance schedule this reminder is for.
     */
    public MaintenanceSchedule $schedule;

    /**
     * How many days before the due date this reminder is firing.
     */
    public int $offsetDays;

    /**
     * Create a new message instance.
     */
    public function __construct(MaintenanceSchedule $schedule, int $offsetDays)
    {
        $this->schedule   = $schedule;
        $this->offsetDays = $offsetDays;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = 'Maintenance Reminder: ' . $this->schedule->name;

        if ($this->schedule->subject) {
            $assetName = $this->schedule->subject->name
                ?? $this->schedule->subject->display_name
                ?? $this->schedule->subject->public_id;
            $subject .= ' — ' . $assetName;
        }

        return new Envelope(subject: $subject);
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'fleetops::mail.maintenance-schedule-reminder',
            with: [
                'schedule'   => $this->schedule,
                'assignee'   => $this->schedule->defaultAssignee,
                'subject'    => $this->schedule->subject,
                'offsetDays' => $this->offsetDays,
            ]
        );
    }
}
