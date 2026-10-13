<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\User;

class UserObserver
{
    /**
     * Handle the User "deleted" event.
     *
     * @return void
     */
    public function deleted(User $user)
    {
        // if the user deleted is a driver, delete their driver record to
        $this->deleteDrivers($user->uuid);
    }

    protected function deleteDrivers(string $userUuid): void
    {
        // A user's drivers can belong to several companies, so this invalidates every company's driver lists
        if (Driver::where('user_uuid', $userUuid)->delete()) {
            Driver::invalidateApiCacheManually();
        }
    }
}
