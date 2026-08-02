<?php

namespace Fleetbase\FleetOps\Listeners;

use Fleetbase\Events\UserRemovedFromCompany;
use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class HandleUserRemovedFromCompany implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     *
     * @param object $event
     *
     * @return void
     */
    public function handle(UserRemovedFromCompany $event)
    {
        // If user has driver record assosciated to the company, remove it
        $this->deleteDriversForCompanyUser($event->company->uuid, $event->user->uuid);
    }

    protected function deleteDriversForCompanyUser(string $companyUuid, string $userUuid): void
    {
        $this->driverQueryForCompanyUser($companyUuid, $userUuid)->delete();
    }

    protected function driverQueryForCompanyUser(string $companyUuid, string $userUuid): mixed
    {
        return $this->driverQuery([
            'company_uuid' => $companyUuid,
            'user_uuid'    => $userUuid,
        ]);
    }

    protected function driverQuery(array $criteria): mixed
    {
        return Driver::where($criteria);
    }
}
