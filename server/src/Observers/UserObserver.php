<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\Models\User;
use Fleetbase\FleetOps\Models\Driver;

class UserObserver
{
    /**
     * Handle the User "deleted" event.
     *
     * @param  \Fleetbase\Models\User  $user
     * @return void
     */
    public function deleted(User $user)
    {
        // if the user deleted is a driver, delete their driver record to
        Driver::where('user_uuid', $user->uuid)->delete();
    }
}
