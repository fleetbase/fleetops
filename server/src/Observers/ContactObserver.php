<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Models\User;

class ContactObserver
{
    /**
     * Handle the Contact "creating" event.
     *
     * @return void
     */
    public function creating(Contact $contact)
    {
        // Create a user account for the contact
        if ($contact->doesntHaveUser()) {
            $contact->createUser();
        }
    }

    /**
     * Handle the Contact "creating" event.
     *
     * @return void
     */
    public function saving(Contact $contact)
    {
        // Get the contacts assosciated user
        if ($contact->doesntHaveUser()) {
            $contact->createUser();
        }

        // Validate email is available to user
        if (!empty($contact->email) && $contact->wasChanged('email') && $this->isEmailUnavailable($contact)) {
            throw new \Exception('Email attempting to update for ' . $contact->type . ' is not available.');
        }

        // Validate phone is available to user
        if (!empty($contact->phone) && $contact->wasChanged('phone') && $this->isPhoneUnavailable($contact)) {
            throw new \Exception('Phone attempting to update for ' . $contact->type . ' is not available.');
        }

        // Sync updates from contact to user
        $contact->syncWithUser();
    }

    /**
     * Handle the Contact "deleted" event.
     *
     * @return void
     */
    public function deleted(Contact $contact)
    {
        // Delete the assosciated user account
        $contact->deleteUser();
    }

    private function isEmailUnavailable(Contact $contact)
    {
        return User::where('email', $contact->email)->whereNot('uuid', $contact->user_uuid)->exists() || Contact::where(['email' => $contact->email, 'company_uuid' => $contact->company_uuid])->whereNot('uuid', $contact->uuid)->exists();
    }

    private function isPhoneUnavailable(Contact $contact)
    {
        return User::where('phone', $contact->phone)->whereNot('uuid', $contact->user_uuid)->exists() || Contact::where(['phone' => $contact->phone, 'company_uuid' => $contact->company_uuid])->whereNot('uuid', $contact->uuid)->exists();
    }
}
