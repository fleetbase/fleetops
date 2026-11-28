<?php

namespace Fleetbase\FleetOps\Mail;

use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent from the console to a driver: the link that connects the Navigator app on
 * their phone to this instance.
 */
class NavigatorAppLinkMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(private Driver $driver, private string $linkUrl, private string $companyName)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Connect the driver app to ' . $this->companyName,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'fleetops::mail.navigator-app-link',
            with: [
                'driver'      => $this->driver,
                'driverName'  => $this->driver->name,
                'companyName' => $this->companyName,
                'linkUrl'     => $this->linkUrl,
            ]
        );
    }
}
