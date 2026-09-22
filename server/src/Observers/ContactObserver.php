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
        if ($contact->exists && $contact->getOriginal('type') === 'customer' && $contact->isDirty('type') && $contact->type !== 'customer') {
            throw new \Exception('Customer contact type cannot be changed.');
        }

        $contact->assertCustomerIdentityIsAvailable();

        // Get the contacts assosciated user
        if ($contact->doesntHaveUser()) {
            $contact->createUser();
        }

        if ($contact->isCustomer()) {
            $contact->normalizeCustomerUser();
        }

        // Sync updates from contact to its login account. This throws when the
        // new email or phone is already used by another account.
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
}
