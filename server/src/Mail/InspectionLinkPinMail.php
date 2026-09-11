<?php

namespace Fleetbase\FleetOps\Mail;

use Fleetbase\FleetOps\Models\InspectionLink;
use Fleetbase\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The PIN for an inspection link, and nothing that would let the email alone
 * open it: the link itself is shared separately. Sent synchronously, so the
 * PIN never sits in a queue's payload.
 */
class InspectionLinkPinMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public InspectionLink $link;
    public string $pin;
    public ?User $recipient;

    public function __construct(InspectionLink $link, string $pin, ?User $recipient = null)
    {
        $this->link      = $link;
        $this->pin       = $pin;
        $this->recipient = $recipient;
    }

    /** The PIN is kept out of the subject, which a locked phone shows. */
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your PIN for the ' . ($this->link->form?->name ?? 'inspection') . ' inspection');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'fleetops::mail.inspection-link-pin',
            with: [
                'pin'         => $this->pin,
                'recipient'   => $this->recipient,
                'form'        => $this->link->form,
                'vehicle'     => $this->link->vehicle,
                'sender'      => $this->link->createdBy,
                'expiresAt'   => $this->link->expires_at,
                'maxAttempts' => InspectionLink::MAX_PIN_ATTEMPTS,
            ]
        );
    }
}
