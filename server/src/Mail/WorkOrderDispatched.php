<?php

namespace Fleetbase\FleetOps\Mail;

use Fleetbase\FleetOps\Models\WorkOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WorkOrderDispatched extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * The work order being dispatched.
     */
    public WorkOrder $workOrder;

    /**
     * Create a new message instance.
     */
    public function __construct(WorkOrder $workOrder)
    {
        $this->workOrder = $workOrder;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = 'Work Order #' . $this->workOrder->public_id;

        if ($this->workOrder->subject) {
            $subject .= ': ' . $this->workOrder->subject;
        }

        return new Envelope(subject: $subject);
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'fleetops::mail.work-order-dispatched',
            with: [
                'workOrder' => $this->workOrder,
                'assignee'  => $this->workOrder->assignee,
                'target'    => $this->workOrder->target,
            ]
        );
    }
}
