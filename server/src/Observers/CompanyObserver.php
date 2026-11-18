<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Support\FleetOps;
use Fleetbase\Models\Company;

class CompanyObserver
{
    /**
     * Handle the Company "created" event.
     *
     * @return void
     */
    public function created(Company $company)
    {
        // Add the default order configs: transport and passenger
        $this->createTransportConfig($company);
        $this->createPassengerConfig($company);
    }

    protected function createTransportConfig(Company $company): void
    {
        FleetOps::createTransportConfig($company);
    }

    protected function createPassengerConfig(Company $company): void
    {
        FleetOps::createPassengerConfig($company);
    }
}
