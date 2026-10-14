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
        // Delete each driver through the model so DriverObserver cleans up after it
        Driver::where('user_uuid', $userUuid)->get()->each->delete();
    }
}
