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
        // if the company user deleted is a driver, delete their driver record in that company
        $this->deleteDrivers($companyUser->user_uuid, $companyUser->company_uuid);
    }

    /**
     * Handle the CompanyUser "updated" event.
     *
     * @return void
     */
    public function updated(CompanyUser $companyUser)
    {
        // If the company user has any driver assosciated update status to same as company_users
        $driver = $this->findDriver($companyUser->user_uuid, $companyUser->company_uuid);
        if ($driver && $companyUser->wasChanged('status')) {
            $driver->update(['status' => $companyUser->status]);
        }
    }

    protected function deleteDrivers(string $userUuid, ?string $companyUuid): void
    {
        if (Driver::where(['user_uuid' => $userUuid, 'company_uuid' => $companyUuid])->delete()) {
            Driver::invalidateApiCacheManually($companyUuid);
        }
    }

    protected function findDriver(string $userUuid, ?string $companyUuid): ?Driver
    {
        return Driver::where(['user_uuid' => $userUuid, 'company_uuid' => $companyUuid])->first();
    }
}
