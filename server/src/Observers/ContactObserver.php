<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\Contact;

class ContactObserver
{
    /**
     * Handle the Contact "creating" event.
     *
     * @return void
     */
    public function created(Contact $contact)
    {
        // Create a user account for the contact
        $contact->createUser();
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
}
