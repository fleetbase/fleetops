<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigTemplates;
use Fleetbase\Models\Company;

class FleetOps
{
    /**
     * Creates or retrieves an existing transport configuration for a given company.
     *
     * The configuration comes from the `transport` order config template: an order is created,
     * dispatched, started, driven and completed.
     *
     * @param Company $company the company for which the transport configuration is being created or retrieved
     *
     * @return OrderConfig the transport configuration associated with the specified company
     */
    public static function createTransportConfig(Company $company): OrderConfig
    {
        return OrderConfigTemplates::create('transport', $company);
    }
}
