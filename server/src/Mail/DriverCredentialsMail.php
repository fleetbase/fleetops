<?php

namespace Fleetbase\FleetOps\Mail;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DriverCredentialsMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(private string $plaintextPassword, private Driver $driver, private User $user)
    {
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $this->driver->loadMissing('company');

        return new Envelope(
            subject: 'Your ' . ($this->driver->company?->name ?? config('app.name')) . ' driver sign-in details',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $this->driver->loadMissing('company');

        return new Content(
            markdown: 'fleetops::mail.driver-credentials',
            with: [
                'driver'            => $this->driver,
                'user'              => $this->user,
                'companyName'       => $this->driver->company?->name ?? config('app.name'),
                'identity'          => $this->user->email ?? $this->user->phone,
                'plaintextPassword' => $this->plaintextPassword,
            ]
        );
    }
}
