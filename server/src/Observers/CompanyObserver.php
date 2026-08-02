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
        // Add the default transport order config
        $this->createTransportConfig($company);
    }

    protected function createTransportConfig(Company $company): void
    {
        FleetOps::createTransportConfig($company);
    }
}
