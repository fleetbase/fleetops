<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\CompanyUser;

class CompanyUserObserver
{
    /**
     * Handle the CompanyUser "deleted" event.
     *
     * @return void
     */
    public function deleted(CompanyUser $companyUser)
    {
        // if the company user deleted is a driver, delete their driver record to
        $this->deleteDrivers($companyUser->user_uuid);
    }

    /**
     * Handle the CompanyUser "updated" event.
     *
     * @return void
     */
    public function updated(CompanyUser $companyUser)
    {
        // If the company user has any driver assosciated update status to same as company_users
        $driver = $this->findDriver($companyUser->user_uuid);
        if ($driver && $companyUser->wasChanged('status')) {
            $driver->update(['status' => $companyUser->status]);
        }
    }

    protected function deleteDrivers(string $userUuid): void
    {
        Driver::where('user_uuid', $userUuid)->delete();
    }

    protected function findDriver(string $userUuid): ?Driver
    {
        return Driver::where('user_uuid', $userUuid)->first();
    }
}
